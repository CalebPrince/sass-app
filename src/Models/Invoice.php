<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class Invoice
{
    public static function create(int $tenantId, ?int $subscriptionId, int $amountCents, string $periodStart, string $periodEnd, string $status = 'paid'): array
    {
        $db = Database::instance();
        $number = 'INV-' . date('Y') . '-' . str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT);
        $id = $db->execute(
            'INSERT INTO invoices (uuid, tenant_id, subscription_id, number, amount_cents, period_start, period_end, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [Tenant::uuid(), $tenantId, $subscriptionId, $number, $amountCents, $periodStart, $periodEnd, $status]
        );
        return $db->first('SELECT * FROM invoices WHERE id = ?', [$id]);
    }

    /** Tenant-scoped billing history, newest first. */
    public static function forTenant(int $tenantId, int $limit = 24): array
    {
        return Database::instance()->select(
            'SELECT * FROM invoices WHERE tenant_id = ? ORDER BY issued_at DESC LIMIT ?',
            [$tenantId, $limit]
        );
    }
}
