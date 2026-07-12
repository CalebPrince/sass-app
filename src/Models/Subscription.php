<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class Subscription
{
    /** Provision the default plan for a brand-new tenant. */
    public static function provision(int $tenantId, string $tier, array $tierConfig): array
    {
        $db = Database::instance();
        $db->execute(
            'INSERT INTO subscriptions (tenant_id, tier, status, seats, resource_limit)
             VALUES (?, ?, ?, ?, ?)',
            [$tenantId, $tier, 'active', $tierConfig['seats'], $tierConfig['resource_limit']]
        );
        return self::forTenant($tenantId);
    }

    /** The multi-tenant barrier: always scoped by tenant_id. */
    public static function forTenant(int $tenantId): ?array
    {
        return Database::instance()->first(
            'SELECT * FROM subscriptions WHERE tenant_id = ?',
            [$tenantId]
        );
    }

    /** Change a tenant's plan (used by the client upgrade flow and admin override). */
    public static function changeTier(int $tenantId, string $tier, array $tierConfig): array
    {
        Database::instance()->execute(
            "UPDATE subscriptions
                SET tier = ?, seats = ?, resource_limit = ?,
                    updated_at = strftime('%Y-%m-%dT%H:%M:%fZ','now')
              WHERE tenant_id = ?",
            [$tier, $tierConfig['seats'], $tierConfig['resource_limit'], $tenantId]
        );
        return self::forTenant($tenantId);
    }

    public static function setStatus(int $tenantId, string $status): void
    {
        Database::instance()->execute(
            "UPDATE subscriptions SET status = ?, updated_at = strftime('%Y-%m-%dT%H:%M:%fZ','now') WHERE tenant_id = ?",
            [$status, $tenantId]
        );
    }

    /** Admin override: bump the raw resource ceiling without changing tier. */
    public static function setResourceLimit(int $tenantId, int $limit): void
    {
        Database::instance()->execute(
            "UPDATE subscriptions SET resource_limit = ?, updated_at = strftime('%Y-%m-%dT%H:%M:%fZ','now') WHERE tenant_id = ?",
            [$limit, $tenantId]
        );
    }
}
