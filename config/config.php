<?php
declare(strict_types=1);

/**
 * Central application configuration.
 *
 * Everything here is plain data — no framework, no magic. The bootstrap
 * (public/index.php) pulls this in once and hands it to the core services.
 */

return [
    'app' => [
        'name'     => 'Nimbus SaaS',
        'env'      => getenv('APP_ENV') ?: 'local',
        'debug'    => (getenv('APP_ENV') ?: 'local') !== 'production',
        'base_url' => getenv('APP_URL') ?: 'http://127.0.0.1:8000',
        'timezone' => 'UTC',
    ],

    'database' => [
        // A single, portable SQLite file. Nothing else to provision.
        'path' => dirname(__DIR__) . '/storage/app.sqlite',
    ],

    'security' => [
        // Session cookie hardening. HTTPS-only should flip on in production.
        'session_name'     => 'nimbus_sid',
        'cookie_secure'    => (getenv('APP_ENV') ?: 'local') === 'production',
        'cookie_httponly'  => true,
        'cookie_samesite'  => 'Lax',
        'session_lifetime' => 60 * 60 * 8,   // 8 hours
        'password_min_len' => 8,
        // Regenerate the session id this often to defeat fixation.
        'session_rotate'   => 60 * 30,       // 30 minutes
    ],

    /**
     * Subscription tiers. This is the single source of truth for what each
     * plan costs, the feature flags it unlocks, and its hard resource ceiling.
     * The billing tab and the admin override screen both read from here.
     */
    'tiers' => [
        'starter' => [
            'label'          => 'Starter',
            'price_cents'    => 0,
            'seats'          => 2,
            'resource_limit' => 1_000,        // usage events / month
            'max_contacts'   => 100,
            'features'       => ['core_dashboard', 'email_support'],
        ],
        'pro' => [
            'label'          => 'Pro',
            'price_cents'    => 4900,
            'seats'          => 10,
            'resource_limit' => 50_000,
            'max_contacts'   => 2_500,
            'features'       => ['core_dashboard', 'email_support', 'api_access', 'advanced_analytics'],
        ],
        'enterprise' => [
            'label'          => 'Enterprise',
            'price_cents'    => 24900,
            'seats'          => 100,
            'resource_limit' => 1_000_000,
            'max_contacts'   => 100_000,
            'features'       => ['core_dashboard', 'priority_support', 'api_access', 'advanced_analytics', 'sso', 'audit_export'],
        ],
    ],

    'roles' => [
        'client'      => 'Client user (tenant member)',
        'owner'       => 'Tenant owner (billing admin for their company)',
        'super_admin' => 'Platform operator (global control center)',
    ],
];
