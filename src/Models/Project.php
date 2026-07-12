<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class Project
{
    public static function create(
        int $tenantId,
        string $name,
        string $description,
        string $engagementType,
        string $deadline
    ): array {
        $db = Database::instance();
        $id = $db->execute(
            'INSERT INTO projects (uuid, tenant_id, name, description, engagement_type, deadline)
             VALUES (?, ?, ?, ?, ?, ?)',
            [Tenant::uuid(), $tenantId, $name, $description, $engagementType, $deadline]
        );
        return self::find($tenantId, $id);
    }

    /** The multi-tenant barrier: always scoped by tenant_id. Includes a live task count. */
    public static function forTenant(int $tenantId): array
    {
        return Database::instance()->select(
            'SELECT p.*, COUNT(t.id) AS task_count
               FROM projects p
               LEFT JOIN tasks t ON t.project_id = p.id AND t.tenant_id = p.tenant_id
              WHERE p.tenant_id = ?
              GROUP BY p.id
              ORDER BY p.created_at DESC',
            [$tenantId]
        );
    }

    public static function find(int $tenantId, int $id): ?array
    {
        return Database::instance()->first(
            'SELECT * FROM projects WHERE tenant_id = ? AND id = ?',
            [$tenantId, $id]
        );
    }

    public static function update(
        int $tenantId,
        int $id,
        string $name,
        string $description,
        string $status,
        string $engagementType,
        string $deadline
    ): ?array {
        Database::instance()->execute(
            "UPDATE projects
                SET name = ?, description = ?, status = ?, engagement_type = ?, deadline = ?,
                    updated_at = strftime('%Y-%m-%dT%H:%M:%fZ','now')
              WHERE tenant_id = ? AND id = ?",
            [$name, $description, $status, $engagementType, $deadline, $tenantId, $id]
        );
        return self::find($tenantId, $id);
    }

    public static function delete(int $tenantId, int $id): void
    {
        Database::instance()->execute(
            'DELETE FROM projects WHERE tenant_id = ? AND id = ?',
            [$tenantId, $id]
        );
    }

    public static function countForTenant(int $tenantId): int
    {
        return (int) Database::instance()->scalar(
            'SELECT COUNT(*) FROM projects WHERE tenant_id = ?',
            [$tenantId]
        );
    }
}
