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

        self::addColumnIfMissing($db, 'contacts', 'entity_type', "TEXT NOT NULL DEFAULT 'individual'");
        self::addColumnIfMissing($db, 'contacts', 'tax_id', "TEXT NOT NULL DEFAULT ''");
        self::addColumnIfMissing($db, 'contacts', 'fiscal_year_end', "TEXT NOT NULL DEFAULT ''");
        self::addColumnIfMissing($db, 'projects', 'engagement_type', "TEXT NOT NULL DEFAULT 'other'");
        self::addColumnIfMissing($db, 'projects', 'deadline', "TEXT NOT NULL DEFAULT ''");
    }

    /**
     * schema.sql only ever CREATE TABLEs (IF NOT EXISTS) — it can't evolve an
     * already-existing table. This adds a column to an existing table exactly
     * once, so upgraded and fresh installs converge on the same schema.
     * $table/$column/$definition are always hardcoded call-site literals, never
     * user input.
     */
    private static function addColumnIfMissing(Database $db, string $table, string $column, string $definition): void
    {
        foreach ($db->select("PRAGMA table_info({$table})") as $col) {
            if ($col['name'] === $column) {
                return;
            }
        }
        $db->pdo()->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
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
