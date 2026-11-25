<?php

declare(strict_types=1);

namespace CreativeBatch;

use PDO;
use PDOException;
use Throwable;

final class Db
{
    private function __construct(private PDO $pdo)
    {
    }

    public static function connect(): self
    {
        $dsn = getenv('CREATIVE_MYSQL_DSN');
        if (!is_string($dsn) || !str_starts_with($dsn, 'mysql:')) {
            throw new \RuntimeException('CREATIVE_MYSQL_DSN must configure MySQL.');
        }
        $pdo = new PDO($dsn, getenv('CREATIVE_MYSQL_USER') ?: '', getenv('CREATIVE_MYSQL_PASSWORD') ?: '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
            PDO::ATTR_TIMEOUT => 5,
            PDO::MYSQL_ATTR_MULTI_STATEMENTS => false,
        ]);
        $pdo->exec("SET time_zone = '+00:00'");
        $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_0900_bin');
        return new self($pdo);
    }

    public function exec(string $sql, array $args = []): int
    {
        $query = $this->pdo->prepare($sql);
        $query->execute($args);
        return $query->rowCount();
    }

    public function one(string $sql, array $args = []): ?array
    {
        $query = $this->pdo->prepare($sql);
        $query->execute($args);
        $row = $query->fetch();
        return $row === false ? null : $row;
    }

    public function all(string $sql, array $args = []): array
    {
        $query = $this->pdo->prepare($sql);
        $query->execute($args);
        return $query->fetchAll();
    }

    public function transaction(callable $fn): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $value = $fn();
            $this->pdo->commit();
            return $value;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public function migrate(?int $through = null): array
    {
        $named = $this->one("SELECT CONCAT('creative-migrate:',LEFT(SHA2(DATABASE(),256),40)) AS lock_name");
        $lock = $named['lock_name'] ?? '';
        if ($lock === '' || (int) ($this->one('SELECT GET_LOCK(?, 10) AS got', [$lock])['got'] ?? 0) !== 1) {
            throw new \RuntimeException('Could not acquire schema migration lock.');
        }
        try {
            $this->pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
                version INT UNSIGNED PRIMARY KEY,
                digest CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                applied_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
            ) ENGINE=InnoDB');
            $applied = [];
            foreach (glob(dirname(__DIR__) . '/database/*.sql') ?: [] as $file) {
                $version = (int) basename($file);
                if ($through !== null && $version > $through) {
                    break;
                }
                $source = file_get_contents($file);
                if ($source === false || $version < 1) {
                    throw new \RuntimeException('Invalid embedded SQL migration.');
                }
                $digest = hash('sha256', str_replace("\r\n", "\n", $source));
                $previous = $this->one('SELECT digest FROM schema_migrations WHERE version=?', [$version]);
                if ($previous !== null) {
                    if (!hash_equals($previous['digest'], $digest)) {
                        throw new \RuntimeException('Applied migration checksum changed.');
                    }
                    continue;
                }
                // Versioned SQL in this repository contains only semicolon-terminated DDL.
                foreach (explode(';', $source) as $statement) {
                    if (trim($statement) !== '') {
                        $this->pdo->exec($statement);
                    }
                }
                $this->exec('INSERT INTO schema_migrations(version,digest) VALUES(?,?)', [$version, $digest]);
                $applied[] = $version;
            }
            return $applied;
        } finally {
            $this->one('SELECT RELEASE_LOCK(?)', [$lock]);
        }
    }

    public static function isDuplicate(Throwable $error): bool
    {
        return $error instanceof PDOException && ($error->errorInfo[1] ?? null) === 1062;
    }

    public function audit(Scope $scope, string $action, string $object, array $detail = []): void
    {
        $this->exec('INSERT INTO audit_log(tenant,actor,action,object_id,detail) VALUES(?,?,?,?,?)',
            [$scope->tenant, $scope->actor, $action, $object, Values::json($detail)]);
    }
}
