<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class GlobalSetting
{
    public static function get(string $key, ?string $default = null): ?string
    {
        $val = Database::instance()->scalar('SELECT value FROM global_settings WHERE key = ?', [$key]);
        return $val === false ? $default : (string) $val;
    }

    public static function set(string $key, string $value): void
    {
        Database::instance()->execute(
            "INSERT INTO global_settings (key, value) VALUES (?, ?)
             ON CONFLICT(key) DO UPDATE SET value = excluded.value,
                    updated_at = strftime('%Y-%m-%dT%H:%M:%fZ','now')",
            [$key, $value]
        );
    }

    public static function all(): array
    {
        return Database::instance()->select('SELECT key, value, updated_at FROM global_settings ORDER BY key');
    }
}
