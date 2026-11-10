<?php

declare(strict_types=1);

namespace TransactionalOutbox\Core;

/**
 * Persistence port for the outbox.
 *
 * The core talks to this interface only, so the storage can be swapped for a
 * different engine without touching the relay logic.
 */
interface OutboxStore
{
    /**
     * Creates the outbox tables. Safe to call repeatedly.
     */
    public function install(): void;

    /**
     * Removes the outbox tables. Destructive.
     */
    public function uninstall(): void;

    /**
     * Stores one message. When the message carries a deduplication key that
     * already exists, the existing row is returned instead of a new one.
     */
    public function store(OutboxMessage $message): OutboxMessage;

    /**
     * @param list<OutboxMessage> $messages
     *
     * @return list<OutboxMessage>
     */
    public function storeMany(array $messages): array;

    /**
     * Claims up to $limit messages that are due, locking them for this
     * process. Messages locked by a dead worker are reclaimed after the claim
     * timeout.
     *
     * @return list<OutboxMessage>
     */
    public function claim(int $limit, int $batchSize): array;

    /**
     * Marks a message as delivered.
     */
    public function markPublished(int $id, string $batchId): void;

    /**
     * Records a failed attempt and schedules the next try.
     */
    public function markFailed(int $id, string $error, int $availableAt): void;

    /**
     * Moves a message to the dead letter table.
     */
    public function deadLetter(int $id, string $error): void;

    /**
     * Records a relay cycle in the batch table.
     */
    public function recordBatch(string $batchId, int $messageCount): void;

    /**
     * Closes a relay cycle.
     */
    public function completeBatch(string $batchId): void;

    /**
     * Number of messages waiting for delivery.
     */
    public function pendingCount(): int;

    /**
     * Number of messages that exhausted their retries.
     */
    public function deadLetterCount(): int;

    /**
     * @return array<string, mixed>
     */
    public function status(): array;
}
