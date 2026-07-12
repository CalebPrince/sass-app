<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class User
{
    public static function create(int $tenantId, string $email, string $password, string $name, string $role = 'client'): array
    {
        $db = Database::instance();
        $id = $db->execute(
            'INSERT INTO users (uuid, tenant_id, email, password_hash, name, role)
             VALUES (?, ?, ?, ?, ?, ?)',
            [Tenant::uuid(), $tenantId, strtolower($email), password_hash($password, PASSWORD_DEFAULT), $name, $role]
        );
        return self::find($id);
    }

    public static function find(int $id): ?array
    {
        return Database::instance()->first('SELECT * FROM users WHERE id = ?', [$id]);
    }

    public static function findByEmail(string $email): ?array
    {
        return Database::instance()->first('SELECT * FROM users WHERE lower(email) = lower(?)', [$email]);
    }

    public static function verifyPassword(array $user, string $password): bool
    {
        return password_verify($password, $user['password_hash']);
    }

    public static function touchLogin(int $id): void
    {
        Database::instance()->execute(
            "UPDATE users SET last_login_at = strftime('%Y-%m-%dT%H:%M:%fZ','now') WHERE id = ?",
            [$id]
        );
    }

    public static function updatePassword(int $id, string $password): void
    {
        Database::instance()->execute(
            "UPDATE users SET password_hash = ?, updated_at = strftime('%Y-%m-%dT%H:%M:%fZ','now') WHERE id = ?",
            [password_hash($password, PASSWORD_DEFAULT), $id]
        );
    }

    public static function setStatus(int $id, string $status): void
    {
        Database::instance()->execute(
            "UPDATE users SET status = ?, updated_at = strftime('%Y-%m-%dT%H:%M:%fZ','now') WHERE id = ?",
            [$status, $id]
        );
    }

    /** Public-safe projection (never leak the password hash to the client). */
    public static function publicView(array $user): array
    {
        return [
            'id'         => (int) $user['id'],
            'uuid'       => $user['uuid'],
            'tenant_id'  => (int) $user['tenant_id'],
            'email'      => $user['email'],
            'name'       => $user['name'],
            'role'       => $user['role'],
            'status'     => $user['status'],
            'last_login' => $user['last_login_at'] ?? null,
        ];
    }
}
