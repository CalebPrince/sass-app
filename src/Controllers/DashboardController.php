<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Models\Contact;
use App\Models\Project;
use App\Models\Subscription;
use App\Models\UsageLog;

/**
 * The Client Control Center API. EVERY read here is scoped to the caller's
 * own tenant via Session::tenantId(); the client can never pass a tenant id
 * of their own choosing, so Company A physically cannot reach Company B's rows.
 */
final class DashboardController extends Controller
{
    public function overview(Request $request): void
    {
        $tenantId = Session::tenantId();
        $subscription = Subscription::forTenant($tenantId);
        $tierConfig = $this->tier($subscription['tier']);

        $used = UsageLog::currentPeriodUsage($tenantId);
        $limit = (int) $subscription['resource_limit'];

        Response::ok([
            'subscription' => [
                'tier'     => $subscription['tier'],
                'label'    => $tierConfig['label'] ?? ucfirst($subscription['tier']),
                'status'   => $subscription['status'],
                'features' => $tierConfig['features'] ?? [],
            ],
            'usage' => [
                'used'    => $used,
                'limit'   => $limit,
                'percent' => $limit > 0 ? min(100, round($used / $limit * 100, 1)) : 0,
            ],
            'contacts' => [
                'used'  => Contact::countForTenant($tenantId),
                'limit' => (int) ($tierConfig['max_contacts'] ?? 0),
            ],
            'projects' => [
                'used'  => Project::countForTenant($tenantId),
                'limit' => (int) ($tierConfig['max_projects'] ?? 0),
            ],
            'activity' => UsageLog::recentForTenant($tenantId, 10),
        ]);
    }

    /**
     * A demo "do work" endpoint. Records a usage event but refuses once the
     * tenant has hit the ceiling defined by its plan — the resource limit in
     * action.
     */
    public function track(Request $request): void
    {
        $tenantId = Session::tenantId();
        $subscription = Subscription::forTenant($tenantId);
        $used = UsageLog::currentPeriodUsage($tenantId);

        if ($used >= (int) $subscription['resource_limit']) {
            Response::error('Monthly resource limit reached. Upgrade your plan to continue.', 402);
        }

        $action = (string) $request->input('action', 'api.call');
        UsageLog::record($tenantId, Session::userId(), $action, 'demo', 1);
        Response::created(['used' => $used + 1], 'Recorded');
    }
}
