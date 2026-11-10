<?php

declare(strict_types=1);

namespace TransactionalOutbox\Core\Storage;

use PDO;
use PDOStatement;
use TransactionalOutbox\Core\Clock;
use TransactionalOutbox\Core\Exception\OutboxException;
use TransactionalOutbox\Core\OutboxConfig;
use TransactionalOutbox\Core\OutboxMessage;
use TransactionalOutbox\Core\OutboxStore;

/**
 * PDO-backed outbox store.
 *
 * The same connection that runs the business transaction must be used here.
 * That is what makes the outbox atomic: the message row and the business
 * write commit together, or neither does.
 */
final class PdoOutboxStore implements OutboxStore
{
    private readonly SchemaInstaller $schema;
    private readonly SqlDialect $dialect;

    public function __construct(
        private readonly PDO $pdo,
        private readonly OutboxConfig $config,
        private readonly Clock $clock,
        ?SqlDialect $dialect = null,
    ) {
        $this->dialect = $dialect ?? SqlDialect::detect($pdo);
        $this->schema = new SchemaInstaller($pdo, $config, $this->dialect);
    }

    public function install(): void
    {
        $this->schema->install();
    }

    public function uninstall(): void
    {
        $this->schema->uninstall();
    }

    public function store(OutboxMessage $message): OutboxMessage
    {
        $now = $this->clock->now();
        $payload = json_encode($message->payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $headers = json_encode($message->headers, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $sql = sprintf(
            'INSERT INTO %s (event_type, aggregate_type, aggregate_id, deduplication_key, payload, headers, attempts, available_at, created_at)
             VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?)',
            $this->schema->messagesTable(),
        );

        $statement = $this->pdo->prepare($sql . $this->dialect()->insertIgnoreDuplicateSuffix());
        $statement->execute([
            $message->eventType,
            $message->aggregateType,
            $message->aggregateId,
            $message->deduplicationKey,
            $payload,
            $headers,
            $message->availableAt ?? $now,
            $now,
        ]);

        // A conflicting deduplication key reports zero affected rows, and the
        // connection's last insert id still points at an older row. Only trust
        // lastInsertId() when the insert actually happened.
        if ($statement->rowCount() === 0) {
            $existing = $message->deduplicationKey !== null
                ? $this->findByDeduplicationKey($message->deduplicationKey)
                : null;

            if ($existing !== null) {
                return $existing;
            }

            throw new OutboxException('Outbox insert was ignored but no existing row could be found.');
        }

        $id = $this->pdo->lastInsertId();
        $id = $id === false ? null : (int) $id;

        return new OutboxMessage(
            eventType: $message->eventType,
            payload: $message->payload,
            aggregateType: $message->aggregateType,
            aggregateId: $message->aggregateId,
            deduplicationKey: $message->deduplicationKey,
            headers: $message->headers,
            id: $id,
            availableAt: $message->availableAt ?? $now,
            createdAt: (string) $now,
        );
    }

    public function storeMany(array $messages): array
    {
        $stored = [];

        foreach ($messages as $message) {
            $stored[] = $this->store($message);
        }

        return $stored;
    }

    public function claim(int $limit, int $batchSize): array
    {
        if ($limit < 1) {
            return [];
        }

        $now = $this->clock->now();
        $batchId = $this->newBatchId();
        $table = $this->schema->messagesTable();

        // A message locked by a worker that died stays locked forever unless it
        // is reclaimed, so locks older than the timeout are considered free.
        $sql = sprintf(
            'SELECT id FROM %s
             WHERE published_at IS NULL
               AND available_at <= ?
               AND (locked_at IS NULL OR locked_at <= ?)
             ORDER BY available_at ASC, id ASC
             LIMIT %d%s',
            $table,
            $limit,
            $this->dialect()->forUpdateSuffix(),
        );

        // The claim runs in its own transaction so the row locks are released
        // before the publisher is called. A transaction that is already open
        // belongs to the caller, so it is joined instead.
        $ownsTransaction = ! $this->pdo->inTransaction();

        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $ids = [];

            $select = $this->pdo->prepare($sql);
            $select->execute([$now, $now - $this->config->claimTimeoutSeconds]);

            foreach ($select->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $ids[] = (int) $row['id'];
            }

            if ($ids === []) {
                if ($ownsTransaction) {
                    $this->pdo->commit();
                }

                return [];
            }

            $placeholders = implode(', ', array_fill(0, count($ids), '?'));
            $update = $this->pdo->prepare(sprintf(
                'UPDATE %s SET locked_at = ?, locked_by = ?, batch_id = ? WHERE id IN (%s)',
                $table,
                $placeholders,
            ));
            $update->execute(array_merge([$now, $this->lockOwner(), $batchId], $ids));

            if ($ownsTransaction) {
                $this->pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw new OutboxException('Failed to claim outbox messages: ' . $e->getMessage(), 0, $e);
        }

        $messages = $this->loadByIds($ids);

        return $messages;
    }

    public function markPublished(int $id, string $batchId): void
    {
        $statement = $this->pdo->prepare(sprintf(
            'UPDATE %s SET published_at = ?, locked_at = NULL, locked_by = NULL, batch_id = ? WHERE id = ?',
            $this->schema->messagesTable(),
        ));
        $statement->execute([$this->clock->now(), $batchId, $id]);
    }

    public function markFailed(int $id, string $error, int $availableAt): void
    {
        $statement = $this->pdo->prepare(sprintf(
            'UPDATE %s
             SET attempts = attempts + 1, last_error = ?, available_at = ?, locked_at = NULL, locked_by = NULL
             WHERE id = ?',
            $this->schema->messagesTable(),
        ));
        $statement->execute([$error, $availableAt, $id]);
    }

    public function deadLetter(int $id, string $error): void
    {
        $message = $this->findById($id);

        if ($message === null) {
            return;
        }

        $now = $this->clock->now();

        $insert = $this->pdo->prepare(sprintf(
            'INSERT INTO %s (event_type, aggregate_type, aggregate_id, deduplication_key, payload, headers, attempts, last_error, created_at, failed_at, batch_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            $this->schema->deadLettersTable(),
        ));
        $insert->execute([
            $message->eventType,
            $message->aggregateType,
            $message->aggregateId,
            $message->deduplicationKey,
            json_encode($message->payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            json_encode($message->headers, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $message->attempts + 1,
            $error,
            $now,
            $now,
            $message->batchId,
        ]);

        $delete = $this->pdo->prepare(sprintf(
            'DELETE FROM %s WHERE id = ?',
            $this->schema->messagesTable(),
        ));
        $delete->execute([$id]);
    }

    public function recordBatch(string $batchId, int $messageCount): void
    {
        $exists = $this->pdo->prepare(sprintf(
            'SELECT 1 FROM %s WHERE id = ?',
            $this->schema->batchesTable(),
        ));
        $exists->execute([$batchId]);

        if ($exists->fetchColumn() === false) {
            $insert = $this->pdo->prepare(sprintf(
                'INSERT INTO %s (id, message_count, created_at) VALUES (?, ?, ?)',
                $this->schema->batchesTable(),
            ));
            $insert->execute([$batchId, $messageCount, $this->clock->now()]);

            return;
        }

        $update = $this->pdo->prepare(sprintf(
            'UPDATE %s SET message_count = ? WHERE id = ?',
            $this->schema->batchesTable(),
        ));
        $update->execute([$messageCount, $batchId]);
    }

    public function completeBatch(string $batchId): void
    {
        $statement = $this->pdo->prepare(sprintf(
            'UPDATE %s SET completed_at = ? WHERE id = ?',
            $this->schema->batchesTable(),
        ));
        $statement->execute([$this->clock->now(), $batchId]);
    }

    public function pendingCount(): int
    {
        $statement = $this->pdo->prepare(sprintf(
            'SELECT COUNT(*) FROM %s WHERE published_at IS NULL',
            $this->schema->messagesTable(),
        ));
        $statement->execute();

        return (int) $statement->fetchColumn();
    }

    public function deadLetterCount(): int
    {
        $statement = $this->pdo->prepare(sprintf(
            'SELECT COUNT(*) FROM %s',
            $this->schema->deadLettersTable(),
        ));
        $statement->execute();

        return (int) $statement->fetchColumn();
    }

    public function status(): array
    {
        return [
            'pending' => $this->pendingCount(),
            'dead_lettered' => $this->deadLetterCount(),
            'table' => $this->config->table,
            'driver' => $this->dialect()->driver,
        ];
    }

    public function findById(int $id): ?OutboxMessage
    {
        $statement = $this->pdo->prepare(sprintf(
            'SELECT * FROM %s WHERE id = ?',
            $this->schema->messagesTable(),
        ));
        $statement->execute([$id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrate($row);
    }

    public function findByDeduplicationKey(string $key): ?OutboxMessage
    {
        if ($key === '') {
            return null;
        }

        $statement = $this->pdo->prepare(sprintf(
            'SELECT * FROM %s WHERE deduplication_key = ? ORDER BY id ASC LIMIT 1',
            $this->schema->messagesTable(),
        ));
        $statement->execute([$key]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * @param list<int> $ids
     *
     * @return list<OutboxMessage>
     */
    private function loadByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $statement = $this->pdo->prepare(sprintf(
            'SELECT * FROM %s WHERE id IN (%s) ORDER BY available_at ASC, id ASC',
            $this->schema->messagesTable(),
            $placeholders,
        ));
        $statement->execute($ids);

        $messages = [];

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $messages[] = $this->hydrate($row);
        }

        return $messages;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): OutboxMessage
    {
        $payload = json_decode((string) $row['payload'], true);
        $headers = json_decode((string) $row['headers'], true);

        return new OutboxMessage(
            eventType: (string) $row['event_type'],
            payload: is_array($payload) ? $payload : [],
            aggregateType: $row['aggregate_type'] !== null ? (string) $row['aggregate_type'] : null,
            aggregateId: $row['aggregate_id'] !== null ? (string) $row['aggregate_id'] : null,
            deduplicationKey: $row['deduplication_key'] !== null ? (string) $row['deduplication_key'] : null,
            headers: is_array($headers) ? $headers : [],
            id: (int) $row['id'],
            attempts: (int) $row['attempts'],
            lastError: $row['last_error'] !== null ? (string) $row['last_error'] : null,
            availableAt: (int) $row['available_at'],
            createdAt: (string) $row['created_at'],
            publishedAt: $row['published_at'] !== null ? (string) $row['published_at'] : null,
            batchId: $row['batch_id'] !== null ? (string) $row['batch_id'] : null,
        );
    }

    private function dialect(): SqlDialect
    {
        return $this->dialect;
    }

    private function lockOwner(): string
    {
        return substr(gethostname() . '-' . getmypid() . '-' . bin2hex(random_bytes(4)), 0, 64);
    }

    private function newBatchId(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
