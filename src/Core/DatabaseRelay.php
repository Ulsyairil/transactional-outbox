<?php

declare(strict_types=1);

namespace TransactionalOutbox\Core;

use TransactionalOutbox\Core\Exception\OutboxException;

/**
 * Delivers pending messages to a publisher.
 *
 * At-least-once semantics: a message that reaches the publisher but fails to
 * be recorded as published is delivered again on the next cycle. Downstream
 * consumers must be idempotent, or use the deduplication key.
 */
final class DatabaseRelay implements Relay
{
    public function __construct(
        private readonly OutboxStore $store,
        private readonly Publisher $publisher,
        private readonly OutboxConfig $config,
        private readonly Clock $clock,
    ) {
    }

    public function run(): RelayResult
    {
        $startedAt = microtime(true);

        $claimed = $this->store->claim($this->config->batchSize, $this->config->batchSize);

        if ($claimed === []) {
            return new RelayResult(durationMs: (microtime(true) - $startedAt) * 1000);
        }

        $batchId = $claimed[0]->batchId ?? '';

        if ($batchId !== '') {
            $this->store->recordBatch($batchId, count($claimed));
        }

        $published = 0;
        $failed = 0;
        $deadLettered = 0;
        $skipped = 0;

        foreach ($claimed as $message) {
            if ($message->id === null) {
                $skipped++;

                continue;
            }

            try {
                $this->publisher->publish($message);
                $this->store->markPublished($message->id, $message->batchId ?? '');
                $published++;
            } catch (\Throwable $e) {
                $failed++;

                if ($this->handleFailure($message, $e)) {
                    $deadLettered++;
                }
            }
        }

        if ($batchId !== '') {
            $this->store->completeBatch($batchId);
        }

        return new RelayResult(
            published: $published,
            failed: $failed,
            deadLettered: $deadLettered,
            skipped: $skipped,
            durationMs: (microtime(true) - $startedAt) * 1000,
        );
    }

    /**
     * Schedules a retry, or dead-letters the message when the attempts are
     * used up.
     *
     * @return bool True when the message was dead-lettered.
     */
    private function handleFailure(OutboxMessage $message, \Throwable $e): bool
    {
        if ($message->id === null) {
            return false;
        }

        $attempts = $message->attempts + 1;
        $error = $e->getMessage();

        if ($attempts >= $this->config->maxAttempts) {
            $this->store->deadLetter($message->id, $error);

            return true;
        }

        $this->store->markFailed($message->id, $error, $this->clock->now() + $this->backoffSeconds($attempts));

        return false;
    }

    /**
     * Exponential backoff: 10s, 20s, 40s, ... capped at max_delay_seconds.
     */
    public function backoffSeconds(int $attempt): int
    {
        if ($attempt < 1) {
            $attempt = 1;
        }

        $delay = $this->config->baseDelaySeconds * (2 ** ($attempt - 1));

        if ($this->config->maxDelaySeconds > 0) {
            $delay = min($delay, $this->config->maxDelaySeconds);
        }

        return (int) $delay;
    }
}
