<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class Task
{
    public static function create(
        int $tenantId,
        int $projectId,
        string $title,
        string $description,
        ?int $assigneeUserId,
        ?string $dueDate
    ): array {
        $db = Database::instance();
        $id = $db->execute(
            'INSERT INTO tasks (uuid, tenant_id, project_id, title, description, assignee_user_id, due_date)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [Tenant::uuid(), $tenantId, $projectId, $title, $description, $assigneeUserId, $dueDate]
        );
        return self::find($tenantId, $id);
    }

    /** The multi-tenant barrier: always scoped by tenant_id (and project_id). */
    public static function forProject(int $tenantId, int $projectId): array
    {
        return Database::instance()->select(
            'SELECT t.*, u.name AS assignee_name
               FROM tasks t
               LEFT JOIN users u ON u.id = t.assignee_user_id
              WHERE t.tenant_id = ? AND t.project_id = ?
              ORDER BY t.created_at DESC',
            [$tenantId, $projectId]
        );
    }

    public static function find(int $tenantId, int $id): ?array
    {
        return Database::instance()->first(
            'SELECT * FROM tasks WHERE tenant_id = ? AND id = ?',
            [$tenantId, $id]
        );
    }

    public static function update(
        int $tenantId,
        int $id,
        string $title,
        string $description,
        string $status,
        ?int $assigneeUserId,
        ?string $dueDate
    ): ?array {
        Database::instance()->execute(
            "UPDATE tasks
                SET title = ?, description = ?, status = ?, assignee_user_id = ?, due_date = ?,
                    updated_at = strftime('%Y-%m-%dT%H:%M:%fZ','now')
              WHERE tenant_id = ? AND id = ?",
            [$title, $description, $status, $assigneeUserId, $dueDate, $tenantId, $id]
        );
        return self::find($tenantId, $id);
    }

    public static function delete(int $tenantId, int $id): void
    {
        Database::instance()->execute(
            'DELETE FROM tasks WHERE tenant_id = ? AND id = ?',
            [$tenantId, $id]
        );
    }
}
