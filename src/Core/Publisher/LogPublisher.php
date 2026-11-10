<?php

declare(strict_types=1);

namespace TransactionalOutbox\Core\Publisher;

use TransactionalOutbox\Core\OutboxMessage;
use TransactionalOutbox\Core\Publisher;

/**
 * Writes every message to a log.
 *
 * Useful for development and for smoke tests: the relay path is exercised
 * without a broker.
 */
final class LogPublisher implements Publisher
{
    /**
     * @param resource $stream
     */
    public function __construct(
        private $stream = null,
        private readonly bool $includePayload = true,
    ) {
        if ($this->stream === null) {
            $this->stream = fopen('php://output', 'wb');
        }
    }

    public function publish(OutboxMessage $message): void
    {
        $line = json_encode([
            'event_type' => $message->eventType,
            'aggregate_type' => $message->aggregateType,
            'aggregate_id' => $message->aggregateId,
            'deduplication_key' => $message->deduplicationKey,
            'payload' => $this->includePayload ? $message->payload : null,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        fwrite($this->stream, $line . PHP_EOL);
    }
}
