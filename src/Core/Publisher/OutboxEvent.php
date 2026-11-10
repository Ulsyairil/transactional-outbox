<?php

declare(strict_types=1);

namespace TransactionalOutbox\Core\Publisher;

/**
 * The event handed to a PSR-14 dispatcher.
 *
 * It carries the payload as an array, not as a serialised PHP object, so the
 * same event can travel through a queue that another service reads.
 */
final class OutboxEvent
{
    /**
     * @param array<string, mixed> $payload
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly string $eventType,
        public readonly array $payload = [],
        public readonly ?string $aggregateType = null,
        public readonly ?string $aggregateId = null,
        public readonly ?string $deduplicationKey = null,
        public readonly array $headers = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'event_type' => $this->eventType,
            'aggregate_type' => $this->aggregateType,
            'aggregate_id' => $this->aggregateId,
            'deduplication_key' => $this->deduplicationKey,
            'payload' => $this->payload,
            'headers' => $this->headers,
        ];
    }
}
