<?php

declare(strict_types=1);

namespace TransactionalOutbox\Core\Storage;

use TransactionalOutbox\Core\Exception\SchemaException;

/**
 * Minimal SQL dialect switch.
 *
 * The outbox only needs three things from a driver: an auto-increment primary
 * key, a unique index that tolerates NULLs, and a portable upsert. Everything
 * else is plain SQL shared by all three engines.
 */
final class SqlDialect
{
    public const SQLITE = 'sqlite';
    public const MYSQL = 'mysql';
    public const PGSQL = 'pgsql';

    private function __construct(public readonly string $driver)
    {
    }

    public static function detect(\PDO $pdo): self
    {
        $driver = (string) $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);

        return self::forDriver($driver);
    }

    public static function forDriver(string $driver): self
    {
        return match (strtolower($driver)) {
            self::SQLITE, 'sqlite2' => new self(self::SQLITE),
            self::MYSQL, 'mariadb' => new self(self::MYSQL),
            self::PGSQL, 'postgres' => new self(self::PGSQL),
            default => throw new SchemaException(sprintf(
                'Unsupported PDO driver "%s". Supported: sqlite, mysql, pgsql.',
                $driver,
            )),
        };
    }

    public function isSqlite(): bool
    {
        return $this->driver === self::SQLITE;
    }

    public function isMysql(): bool
    {
        return $this->driver === self::MYSQL;
    }

    public function isPgsql(): bool
    {
        return $this->driver === self::PGSQL;
    }

    /**
     * Quotes an identifier so table and column names stay safe when they come
     * from configuration.
     */
    public function quote(string $identifier): string
    {
        if ($this->isMysql()) {
            return '`' . str_replace('`', '``', $identifier) . '`';
        }

        return '"' . str_replace('"', '""', $identifier) . '"';
    }

    /**
     * The auto-increment primary key column definition for the message table.
     */
    public function primaryKeyDefinition(): string
    {
        return match ($this->driver) {
            self::SQLITE => 'id INTEGER PRIMARY KEY AUTOINCREMENT',
            self::MYSQL => 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT',
            self::PGSQL => 'id BIGSERIAL PRIMARY KEY',
            default => throw new SchemaException(sprintf('Unsupported PDO driver "%s".', $this->driver)),
        };
    }

    /**
     * Suffix that turns a plain INSERT into an insert that never fails on a
     * duplicated deduplication key.
     */
    public function insertIgnoreDuplicateSuffix(string $conflictColumn = 'deduplication_key'): string
    {
        return match ($this->driver) {
            self::MYSQL => ' ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)',
            default => sprintf(' ON CONFLICT (%s) DO NOTHING', $this->quote($conflictColumn)),
        };
    }

    /**
     * Timestamp expression for "now" in seconds, portable across engines.
     */
    public function currentTimestampExpression(): string
    {
        return match ($this->driver) {
            self::SQLITE => "CAST(strftime('%s', 'now') AS INTEGER)",
            self::MYSQL => 'UNIX_TIMESTAMP()',
            self::PGSQL => 'EXTRACT(EPOCH FROM NOW())::BIGINT',
            default => throw new SchemaException(sprintf('Unsupported PDO driver "%s".', $this->driver)),
        };
    }

    /**
     * Suffix that locks the selected rows for the duration of the transaction.
     *
     * MySQL and PostgreSQL support real row locks. SQLite has a single writer,
     * so a plain write transaction already serialises the claim.
     */
    public function forUpdateSuffix(): string
    {
        return match ($this->driver) {
            self::MYSQL, self::PGSQL => ' FOR UPDATE SKIP LOCKED',
            default => '',
        };
    }
}
