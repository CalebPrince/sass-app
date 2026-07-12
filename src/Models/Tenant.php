<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class Tenant
{
    public static function create(string $name): array
    {
        $db = Database::instance();
        $uuid = self::uuid();
        $slug = self::uniqueSlug($name);
        $id = $db->execute(
            'INSERT INTO tenants (uuid, name, slug) VALUES (?, ?, ?)',
            [$uuid, $name, $slug]
        );
        return self::find($id);
    }

    public static function find(int $id): ?array
    {
        return Database::instance()->first('SELECT * FROM tenants WHERE id = ?', [$id]);
    }

    public static function setStatus(int $id, string $status): void
    {
        Database::instance()->execute(
            "UPDATE tenants SET status = ?, updated_at = strftime('%Y-%m-%dT%H:%M:%fZ','now') WHERE id = ?",
            [$status, $id]
        );
    }

    private static function uniqueSlug(string $name): string
    {
        $base = preg_replace('/[^a-z0-9]+/', '-', strtolower(trim($name))) ?: 'tenant';
        $base = trim($base, '-');
        $slug = $base;
        $db = Database::instance();
        $i = 1;
        while ($db->scalar('SELECT COUNT(*) FROM tenants WHERE slug = ?', [$slug]) > 0) {
            $slug = $base . '-' . (++$i);
        }
        return $slug;
    }

    public static function uuid(): string
    {
        $d = random_bytes(16);
        $d[6] = chr((ord($d[6]) & 0x0f) | 0x40);
        $d[8] = chr((ord($d[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
    }
}
