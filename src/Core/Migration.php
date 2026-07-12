<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Applies database/schema.sql against the SQLite file. Idempotent — every
 * statement uses IF NOT EXISTS, so running it repeatedly is a no-op.
 */
final class Migration
{
    public static function run(Database $db, string $schemaPath): void
    {
        if (!is_file($schemaPath)) {
            throw new \RuntimeException("Schema file not found: {$schemaPath}");
        }
        $sql = file_get_contents($schemaPath);
        // PDO::exec on SQLite happily runs a multi-statement script.
        $db->pdo()->exec($sql);
    }

    /** True once the core tables exist — used to decide whether to seed. */
    public static function isFresh(Database $db): bool
    {
        $count = $db->scalar(
            "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='users'"
        );
        return (int) $count === 0;
    }
}
