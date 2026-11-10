<?php

declare(strict_types=1);

namespace TransactionalOutbox\Core\Storage;

use PDO;
use TransactionalOutbox\Core\OutboxConfig;

/**
 * Creates and drops the outbox tables.
 *
 * The schema is identical on SQLite, MySQL and PostgreSQL apart from the
 * auto-increment column, the timestamp expression and the index syntax.
 */
final class SchemaInstaller
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly OutboxConfig $config,
        private readonly SqlDialect $dialect,
    ) {
    }

    public function install(): void
    {
        $this->installMessages();
        $this->installBatches();
        $this->installDeadLetters();
    }

    public function uninstall(): void
    {
        foreach ([
            $this->config->table,
            $this->config->batchTable,
            $this->config->deadLetterTable,
        ] as $table) {
            $this->pdo->exec('DROP TABLE IF EXISTS ' . $this->dialect->quote($table));
        }
    }

    public function messagesTable(): string
    {
        return $this->dialect->quote($this->config->table);
    }

    public function batchesTable(): string
    {
        return $this->dialect->quote($this->config->batchTable);
    }

    public function deadLettersTable(): string
    {
        return $this->dialect->quote($this->config->deadLetterTable);
    }

    private function installMessages(): void
    {
        $table = $this->config->table;

        $this->pdo->exec(sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                %s,
                event_type VARCHAR(191) NOT NULL,
                aggregate_type VARCHAR(191) NULL,
                aggregate_id VARCHAR(191) NULL,
                deduplication_key VARCHAR(191) NULL,
                payload TEXT NOT NULL,
                headers TEXT NOT NULL,
                attempts INT NOT NULL DEFAULT 0,
                last_error TEXT NULL,
                available_at BIGINT NOT NULL,
                created_at BIGINT NOT NULL,
                published_at BIGINT NULL,
                batch_id VARCHAR(36) NULL,
                locked_at BIGINT NULL,
                locked_by VARCHAR(64) NULL
            )',
            $this->dialect->quote($table),
            $this->dialect->primaryKeyDefinition(),
        ));

        // A NULL deduplication key means "no dedup", so the unique index must
        // ignore NULLs. PostgreSQL and SQLite do that by default, MySQL does
        // not, hence the 191 character prefix length on MySQL.
        $this->ensureIndex(
            'outbox_messages_dedup_uq',
            $table,
            'deduplication_key',
            true,
        );

        $this->ensureIndex('outbox_messages_pending_idx', $table, 'available_at, id');
        $this->ensureIndex('outbox_messages_batch_idx', $table, 'batch_id');
    }

    private function installBatches(): void
    {
        $table = $this->config->batchTable;

        $this->pdo->exec(sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                id VARCHAR(36) NOT NULL PRIMARY KEY,
                message_count INT NOT NULL DEFAULT 0,
                created_at BIGINT NOT NULL,
                completed_at BIGINT NULL
            )',
            $this->dialect->quote($table),
        ));
    }

    private function installDeadLetters(): void
    {
        $table = $this->config->deadLetterTable;

        $this->pdo->exec(sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                %s,
                event_type VARCHAR(191) NOT NULL,
                aggregate_type VARCHAR(191) NULL,
                aggregate_id VARCHAR(191) NULL,
                deduplication_key VARCHAR(191) NULL,
                payload TEXT NOT NULL,
                headers TEXT NOT NULL,
                attempts INT NOT NULL DEFAULT 0,
                last_error TEXT NULL,
                created_at BIGINT NOT NULL,
                failed_at BIGINT NOT NULL,
                batch_id VARCHAR(36) NULL
            )',
            $this->dialect->quote($table),
            $this->dialect->primaryKeyDefinition(),
        ));

        $this->ensureIndex('outbox_dead_letters_event_idx', $table, 'event_type');
    }

    /**
     * Creates an index when it does not exist yet.
     *
     * MySQL has no `CREATE INDEX IF NOT EXISTS`, so the existence check is done
     * by hand there.
     */
    private function ensureIndex(string $indexName, string $table, string $columns, bool $unique = false): void
    {
        if ($this->dialect->isMysql()) {
            $statement = $this->pdo->prepare(
                'SELECT 1 FROM information_schema.statistics
                 WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?',
            );
            $statement->execute([$table, $indexName]);

            if ($statement->fetchColumn() !== false) {
                return;
            }

            // MySQL has no `CREATE INDEX IF NOT EXISTS`.
            $this->pdo->exec(sprintf(
                'CREATE %sINDEX %s ON %s (%s)',
                $unique ? 'UNIQUE ' : '',
                $this->dialect->quote($indexName),
                $this->dialect->quote($table),
                $columns,
            ));

            return;
        }

        $this->pdo->exec(sprintf(
            'CREATE %sINDEX IF NOT EXISTS %s ON %s (%s)',
            $unique ? 'UNIQUE ' : '',
            $this->dialect->quote($indexName),
            $this->dialect->quote($table),
            $columns,
        ));
    }
}
