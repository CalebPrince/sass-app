<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class AuditLog
{
    public static function record(?int $actorId, string $action, string $targetType = '', string $targetId = '', array $detail = [], string $ip = ''): void
    {
        Database::instance()->execute(
            'INSERT INTO audit_logs (actor_user_id, action, target_type, target_id, detail, ip_address)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$actorId, $action, $targetType, $targetId, json_encode($detail, JSON_UNESCAPED_SLASHES), $ip]
        );
    }

    public static function recent(int $limit = 50): array
    {
        return Database::instance()->select(
            'SELECT a.*, u.email AS actor_email
               FROM audit_logs a LEFT JOIN users u ON u.id = a.actor_user_id
              ORDER BY a.created_at DESC LIMIT ?',
            [$limit]
        );
    }
}
