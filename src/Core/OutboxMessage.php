<?php

declare(strict_types=1);

namespace TransactionalOutbox\Core;

/**
 * A message stored in the outbox.
 *
 * Payloads are JSON, not serialized PHP objects: the row must stay readable
 * by another process, another language, and by a human during an incident.
 */
final class OutboxMessage
{
    /**
     * @param array<string, mixed> $payload
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly string $eventType,
        public readonly array $payload,
        public readonly ?string $aggregateType = null,
        public readonly ?string $aggregateId = null,
        public readonly ?string $deduplicationKey = null,
        public readonly array $headers = [],
        public readonly ?int $id = null,
        public readonly int $attempts = 0,
        public readonly ?string $lastError = null,
        public readonly ?int $availableAt = null,
        public readonly ?string $createdAt = null,
        public readonly ?string $publishedAt = null,
        public readonly ?string $batchId = null,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, string> $headers
     */
    public static function create(
        string $eventType,
        array $payload = [],
        ?string $aggregateType = null,
        ?string $aggregateId = null,
        ?string $deduplicationKey = null,
        array $headers = [],
    ): self {
        return new self(
            eventType: $eventType,
            payload: $payload,
            aggregateType: $aggregateType,
            aggregateId: $aggregateId,
            deduplicationKey: $deduplicationKey,
            headers: $headers,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'event_type' => $this->eventType,
            'aggregate_type' => $this->aggregateType,
            'aggregate_id' => $this->aggregateId,
            'deduplication_key' => $this->deduplicationKey,
            'payload' => $this->payload,
            'headers' => $this->headers,
            'attempts' => $this->attempts,
            'last_error' => $this->lastError,
            'available_at' => $this->availableAt,
            'created_at' => $this->createdAt,
            'published_at' => $this->publishedAt,
            'batch_id' => $this->batchId,
        ];
    }
}
