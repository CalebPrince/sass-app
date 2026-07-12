<?php
declare(strict_types=1);

/**
 * First-run seed. Creates the platform operator, a couple of demo tenants
 * with data, and default global settings — so the app is explorable the
 * moment `python server.py` boots it.
 *
 * Called once by public/index.php when the schema is freshly created.
 */

use App\Core\Database;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Subscription;
use App\Models\Invoice;
use App\Models\UsageLog;
use App\Models\GlobalSetting;

function seed_database(Database $db, array $config): void
{
    $db->transaction(function () use ($config) {
        // --- Global settings ---
        GlobalSetting::set('maintenance_mode', '0');
        GlobalSetting::set('signups_open', '1');
        GlobalSetting::set('platform_name', $config['app']['name']);

        // --- Platform operator (super_admin) on a dedicated system tenant ---
        $systemTenant = Tenant::create('Nimbus Platform');
        Subscription::provision((int) $systemTenant['id'], 'enterprise', $config['tiers']['enterprise']);
        User::create((int) $systemTenant['id'], 'admin@nimbus.test', 'Admin1234', 'Platform Operator', 'super_admin');

        // --- Demo tenant A (Pro) ---
        $acme = Tenant::create('Acme Corp');
        $acmeSub = Subscription::provision((int) $acme['id'], 'pro', $config['tiers']['pro']);
        $acmeOwner = User::create((int) $acme['id'], 'owner@acme.test', 'Acme1234', 'Ada Acme', 'owner');
        Invoice::create((int) $acme['id'], (int) $acmeSub['id'], $config['tiers']['pro']['price_cents'],
            date('Y-m-01\T00:00:00\Z'), date('Y-m-01\T00:00:00\Z', strtotime('+1 month')), 'paid');
        for ($i = 0; $i < 40; $i++) {
            UsageLog::record((int) $acme['id'], (int) $acmeOwner['id'], 'api.call', 'reports', 1, ['seed' => true]);
        }

        // --- Demo tenant B (Starter) — proves isolation from tenant A ---
        $globex = Tenant::create('Globex Inc');
        Subscription::provision((int) $globex['id'], 'starter', $config['tiers']['starter']);
        $globexOwner = User::create((int) $globex['id'], 'owner@globex.test', 'Globex1234', 'Guy Globex', 'owner');
        for ($i = 0; $i < 12; $i++) {
            UsageLog::record((int) $globex['id'], (int) $globexOwner['id'], 'api.call', 'exports', 1, ['seed' => true]);
        }
    });

    fwrite(STDERR, "[seed] Demo data created. Operator: admin@nimbus.test / Admin1234\n");
}
