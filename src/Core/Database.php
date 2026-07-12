<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;

/**
 * Thin, safe wrapper over a single PDO connection to the SQLite database.
 *
 * Every query in the app funnels through here using PREPARED STATEMENTS —
 * user input is bound, never concatenated. That is the whole SQL-injection
 * defense: there is no other way to reach the database.
 */
final class Database
{
    private static ?Database $instance = null;
    private PDO $pdo;

    private function __construct(string $path)
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        try {
            $this->pdo = new PDO('sqlite:' . $path, null, null, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            throw new \RuntimeException('Database connection failed: ' . $e->getMessage());
        }

        // Enforce referential integrity + concurrency-friendly journaling.
        $this->pdo->exec('PRAGMA foreign_keys = ON;');
        $this->pdo->exec('PRAGMA journal_mode = WAL;');
        $this->pdo->exec('PRAGMA busy_timeout = 5000;');
    }

    public static function boot(string $path): void
    {
        if (self::$instance === null) {
            self::$instance = new self($path);
        }
    }

    public static function instance(): Database
    {
        if (self::$instance === null) {
            throw new \RuntimeException('Database not booted. Call Database::boot() first.');
        }
        return self::$instance;
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /** Run a prepared statement and return every row. */
    public function select(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** Run a prepared statement and return the first row (or null). */
    public function first(string $sql, array $params = []): ?array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** Run a prepared write; return the new row id for inserts. */
    public function execute(string $sql, array $params = []): int
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return (int) $this->pdo->lastInsertId();
    }

    /** Scalar helper for COUNT/SUM style aggregates. */
    public function scalar(string $sql, array $params = []): mixed
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }

    public function transaction(callable $work): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $work($this);
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}
