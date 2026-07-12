<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Database;
use App\Models\User;
use App\Models\Tenant;
use App\Models\Subscription;
use App\Models\AuditLog;
use App\Models\GlobalSetting;

/**
 * The Global Admin Control Center API. Reachable only behind Guards::admin,
 * so it deliberately ignores tenant scoping: an operator sees the whole
 * platform. Every override is written to the audit trail.
 */
final class AdminController extends Controller
{
    /** Real-time platform metrics: active users, MRR, system health. */
    public function metrics(Request $request): void
    {
        $db = Database::instance();

        $activeUsers = (int) $db->scalar("SELECT COUNT(*) FROM users WHERE status = 'active'");
        $totalTenants = (int) $db->scalar('SELECT COUNT(*) FROM tenants');
        $bannedUsers = (int) $db->scalar("SELECT COUNT(*) FROM users WHERE status = 'banned'");

        // MRR = sum of monthly price for every active, paid subscription.
        $mrrCents = 0;
        $tierBreakdown = [];
        foreach ($this->config['tiers'] as $key => $t) {
            $count = (int) $db->scalar(
                "SELECT COUNT(*) FROM subscriptions WHERE tier = ? AND status = 'active'",
                [$key]
            );
            $tierBreakdown[$key] = $count;
            $mrrCents += $count * $t['price_cents'];
        }

        $eventsToday = (int) $db->scalar(
            "SELECT COUNT(*) FROM usage_logs WHERE created_at >= strftime('%Y-%m-%dT00:00:00Z','now')"
        );

        Response::ok([
            'active_users'   => $activeUsers,
            'banned_users'   => $bannedUsers,
            'total_tenants'  => $totalTenants,
            'mrr_cents'      => $mrrCents,
            'arr_cents'      => $mrrCents * 12,
            'tier_breakdown' => $tierBreakdown,
            'health' => [
                'db_writable'    => is_writable($this->config['database']['path']),
                'events_today'   => $eventsToday,
                'maintenance'    => GlobalSetting::get('maintenance_mode', '0') === '1',
                'signups_open'   => GlobalSetting::get('signups_open', '1') === '1',
            ],
        ]);
    }

    /** Paginated user table joined with tenant + plan for the console grid. */
    public function users(Request $request): void
    {
        $search = trim((string) $request->query('q', ''));
        $params = [];
        $where = '';
        if ($search !== '') {
            $where = 'WHERE u.email LIKE ? OR u.name LIKE ? OR t.name LIKE ?';
            $like = '%' . $search . '%';
            $params = [$like, $like, $like];
        }

        $rows = Database::instance()->select(
            "SELECT u.id, u.email, u.name, u.role, u.status, u.last_login_at,
                    t.id AS tenant_id, t.name AS tenant_name, t.status AS tenant_status,
                    s.tier, s.status AS sub_status, s.resource_limit
               FROM users u
               JOIN tenants t ON t.id = u.tenant_id
          LEFT JOIN subscriptions s ON s.tenant_id = t.id
               {$where}
           ORDER BY u.created_at DESC
              LIMIT 100",
            $params
        );
        Response::ok(['users' => $rows]);
    }

    /** Ban / reinstate a user. */
    public function setUserStatus(Request $request, array $args): void
    {
        $userId = (int) $args['id'];
        $status = (string) $request->input('status', '');
        if (!in_array($status, ['active', 'banned'], true)) {
            Response::error('status must be active or banned', 422);
        }

        $target = User::find($userId);
        if ($target === null) {
            Response::notFound('User not found');
        }
        // An operator cannot ban themselves out of the console.
        if ($userId === Session::userId()) {
            Response::error('You cannot change your own status', 422);
        }

        User::setStatus($userId, $status);
        AuditLog::record(Session::userId(), 'user.status_changed', 'user', (string) $userId, ['status' => $status], $request->ip());
        Response::ok([], "User {$status}");
    }

    /** Manually move a tenant to a different plan (override). */
    public function overrideTier(Request $request, array $args): void
    {
        $tenantId = (int) $args['id'];
        $tier = (string) $request->input('tier', '');
        $tierConfig = $this->tier($tier);
        if ($tierConfig === null) {
            Response::error('Unknown plan tier', 422);
        }
        if (Tenant::find($tenantId) === null) {
            Response::notFound('Tenant not found');
        }

        Subscription::changeTier($tenantId, $tier, $tierConfig);
        AuditLog::record(Session::userId(), 'subscription.override', 'tenant', (string) $tenantId, ['tier' => $tier], $request->ip());
        Response::ok([], 'Tier overridden to ' . $tierConfig['label']);
    }

    /** Manually raise/lower a tenant's raw resource ceiling (override). */
    public function overrideLimit(Request $request, array $args): void
    {
        $tenantId = (int) $args['id'];
        $limit = (int) $request->input('resource_limit', -1);
        if ($limit < 0) {
            Response::error('resource_limit must be a non-negative integer', 422);
        }
        if (Tenant::find($tenantId) === null) {
            Response::notFound('Tenant not found');
        }

        Subscription::setResourceLimit($tenantId, $limit);
        AuditLog::record(Session::userId(), 'limit.override', 'tenant', (string) $tenantId, ['resource_limit' => $limit], $request->ip());
        Response::ok([], 'Resource limit updated');
    }

    /** Suspend / reactivate an entire tenant. */
    public function setTenantStatus(Request $request, array $args): void
    {
        $tenantId = (int) $args['id'];
        $status = (string) $request->input('status', '');
        if (!in_array($status, ['active', 'suspended'], true)) {
            Response::error('status must be active or suspended', 422);
        }
        if (Tenant::find($tenantId) === null) {
            Response::notFound('Tenant not found');
        }

        Tenant::setStatus($tenantId, $status);
        AuditLog::record(Session::userId(), 'tenant.status_changed', 'tenant', (string) $tenantId, ['status' => $status], $request->ip());
        Response::ok([], "Tenant {$status}");
    }

    /** Toggle platform-wide switches (maintenance mode, signups). */
    public function updateSettings(Request $request): void
    {
        foreach (['maintenance_mode', 'signups_open'] as $key) {
            $val = $request->input($key);
            if ($val !== null) {
                GlobalSetting::set($key, $val ? '1' : '0');
            }
        }
        AuditLog::record(Session::userId(), 'settings.updated', 'global', '', $request->all(), $request->ip());
        Response::ok(GlobalSetting::all(), 'Settings saved');
    }

    /** The audit trail feed. */
    public function audit(Request $request): void
    {
        Response::ok(['logs' => AuditLog::recent(80)]);
    }
}
