<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class Contact
{
    public static function create(
        int $tenantId,
        string $name,
        string $email,
        string $phone,
        string $company,
        string $notes
    ): array {
        $db = Database::instance();
        $id = $db->execute(
            'INSERT INTO contacts (uuid, tenant_id, name, email, phone, company, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [Tenant::uuid(), $tenantId, $name, $email, $phone, $company, $notes]
        );
        return self::find($tenantId, $id);
    }

    /** The multi-tenant barrier: always scoped by tenant_id. */
    public static function forTenant(int $tenantId): array
    {
        return Database::instance()->select(
            'SELECT * FROM contacts WHERE tenant_id = ? ORDER BY created_at DESC',
            [$tenantId]
        );
    }

    public static function find(int $tenantId, int $id): ?array
    {
        return Database::instance()->first(
            'SELECT * FROM contacts WHERE tenant_id = ? AND id = ?',
            [$tenantId, $id]
        );
    }

    public static function update(
        int $tenantId,
        int $id,
        string $name,
        string $email,
        string $phone,
        string $company,
        string $status,
        string $notes
    ): ?array {
        Database::instance()->execute(
            "UPDATE contacts
                SET name = ?, email = ?, phone = ?, company = ?, status = ?, notes = ?,
                    updated_at = strftime('%Y-%m-%dT%H:%M:%fZ','now')
              WHERE tenant_id = ? AND id = ?",
            [$name, $email, $phone, $company, $status, $notes, $tenantId, $id]
        );
        return self::find($tenantId, $id);
    }

    public static function delete(int $tenantId, int $id): void
    {
        Database::instance()->execute(
            'DELETE FROM contacts WHERE tenant_id = ? AND id = ?',
            [$tenantId, $id]
        );
    }

    public static function countForTenant(int $tenantId): int
    {
        return (int) Database::instance()->scalar(
            'SELECT COUNT(*) FROM contacts WHERE tenant_id = ?',
            [$tenantId]
        );
    }
}
