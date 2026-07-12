<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Models\Subscription;
use App\Models\Invoice;
use App\Models\UsageLog;

/**
 * The client-facing "Subscription" tab: current plan, unlocked features,
 * invoice history, and self-service plan changes — all tenant-scoped.
 */
final class SubscriptionController extends Controller
{
    public function show(Request $request): void
    {
        $tenantId = Session::tenantId();
        $subscription = Subscription::forTenant($tenantId);
        $tierConfig = $this->tier($subscription['tier']);

        Response::ok([
            'plan' => [
                'tier'           => $subscription['tier'],
                'label'          => $tierConfig['label'] ?? ucfirst($subscription['tier']),
                'status'         => $subscription['status'],
                'seats'          => (int) $subscription['seats'],
                'resource_limit' => (int) $subscription['resource_limit'],
                'price_cents'    => $tierConfig['price_cents'] ?? 0,
                'features'       => $tierConfig['features'] ?? [],
                'renews_at'      => $subscription['current_period_end'],
            ],
            'catalog'  => $this->catalog(),
            'invoices' => Invoice::forTenant($tenantId),
        ]);
    }

    /** Self-service upgrade / downgrade between published tiers. */
    public function change(Request $request): void
    {
        $tenantId = Session::tenantId();
        $tier = (string) $request->input('tier', '');
        $tierConfig = $this->tier($tier);

        if ($tierConfig === null) {
            Response::error('Unknown plan tier', 422);
        }

        $updated = Subscription::changeTier($tenantId, $tier, $tierConfig);

        // Generate an invoice for paid tiers to populate billing history.
        if (($tierConfig['price_cents'] ?? 0) > 0) {
            Invoice::create(
                $tenantId,
                (int) $updated['id'],
                $tierConfig['price_cents'],
                date('Y-m-d\TH:i:s\Z'),
                date('Y-m-d\TH:i:s\Z', strtotime('+30 days')),
                'paid'
            );
        }

        UsageLog::record($tenantId, Session::userId(), 'subscription.changed', 'subscription', 1, ['tier' => $tier]);
        Response::ok(['tier' => $tier], 'Plan updated to ' . ($tierConfig['label'] ?? $tier));
    }

    private function catalog(): array
    {
        $catalog = [];
        foreach ($this->config['tiers'] as $key => $t) {
            $catalog[] = [
                'tier'           => $key,
                'label'          => $t['label'],
                'price_cents'    => $t['price_cents'],
                'seats'          => $t['seats'],
                'resource_limit' => $t['resource_limit'],
                'features'       => $t['features'],
            ];
        }
        return $catalog;
    }
}
