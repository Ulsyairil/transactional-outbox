<?php

declare(strict_types=1);

namespace TransactionalOutbox\Core;

use PDO;
use TransactionalOutbox\Core\Exception\OutboxException;
use TransactionalOutbox\Core\Storage\PdoOutboxStore;

/**
 * The application-facing entry point.
 *
 * Call `store()` inside the same transaction as the business write. The message
 * and the data land together or not at all.
 */
final class Outbox
{
    public function __construct(
        private readonly OutboxStore $store,
        private readonly ?PDO $connection = null,
    ) {
    }

    /**
     * Convenience factory for the plain PDO case.
     */
    public static function forConnection(
        PDO $pdo,
        ?OutboxConfig $config = null,
        ?Clock $clock = null,
    ): self {
        $config ??= OutboxConfig::fromArray();
        $store = new PdoOutboxStore($pdo, $config, $clock ?? new SystemClock());

        if ($config->autoInstall) {
            $store->install();
        }

        return new self($store, $pdo);
    }

    public function store(OutboxMessage $message): OutboxMessage
    {
        return $this->store->store($message);
    }

    /**
     * @param string $eventType
     * @param array<string, mixed> $payload
     * @param array<string, string> $headers
     */
    public function publish(
        string $eventType,
        array $payload = [],
        ?string $aggregateType = null,
        ?string $aggregateId = null,
        ?string $deduplicationKey = null,
        array $headers = [],
    ): OutboxMessage {
        return $this->store->store(OutboxMessage::create(
            eventType: $eventType,
            payload: $payload,
            aggregateType: $aggregateType,
            aggregateId: $aggregateId,
            deduplicationKey: $deduplicationKey,
            headers: $headers,
        ));
    }

    /**
     * Runs the given work and stores the message in the same transaction.
     *
     * The work runs first, then the message is written. If either fails, the
     * transaction rolls back and neither survives.
     *
     * @template T
     *
     * @param callable(): T $work
     * @param callable(): OutboxMessage $message
     *
     * @return T
     */
    public function transaction(callable $work, callable $message): mixed
    {
        if ($this->connection === null) {
            throw new OutboxException('No database connection available for a transactional store.');
        }

        $ownsTransaction = ! $this->connection->inTransaction();

        if ($ownsTransaction) {
            $this->connection->beginTransaction();
        }

        try {
            $result = $work();

            $this->store->store($message());

            if ($ownsTransaction) {
                $this->connection->commit();
            }

            return $result;
        } catch (\Throwable $e) {
            if ($ownsTransaction && $this->connection->inTransaction()) {
                $this->connection->rollBack();
            }

            throw $e;
        }
    }

    public function install(): void
    {
        $this->store->install();
    }

    public function uninstall(): void
    {
        $this->store->uninstall();
    }

    public function storeInstance(): OutboxStore
    {
        return $this->store;
    }

    public function pendingCount(): int
    {
        return $this->store->pendingCount();
    }

    public function deadLetterCount(): int
    {
        return $this->store->deadLetterCount();
    }

    /**
     * @return array<string, mixed>
     */
    public function status(): array
    {
        return $this->store->status();
    }
}
