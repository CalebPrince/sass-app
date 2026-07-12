<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class UsageLog
{
    public static function record(int $tenantId, ?int $userId, string $action, string $resource = '', int $quantity = 1, array $metadata = []): void
    {
        Database::instance()->execute(
            'INSERT INTO usage_logs (tenant_id, user_id, action, resource, quantity, metadata)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$tenantId, $userId, $action, $resource, $quantity, json_encode($metadata, JSON_UNESCAPED_SLASHES)]
        );
    }

    /** Tenant-scoped recent activity feed. */
    public static function recentForTenant(int $tenantId, int $limit = 25): array
    {
        return Database::instance()->select(
            'SELECT * FROM usage_logs WHERE tenant_id = ? ORDER BY created_at DESC LIMIT ?',
            [$tenantId, $limit]
        );
    }

    /** Sum of consumed units this billing period — the meter for resource_limit. */
    public static function currentPeriodUsage(int $tenantId): int
    {
        return (int) Database::instance()->scalar(
            "SELECT COALESCE(SUM(quantity), 0) FROM usage_logs
              WHERE tenant_id = ? AND created_at >= strftime('%Y-%m-01T00:00:00Z','now')",
            [$tenantId]
        );
    }
}
