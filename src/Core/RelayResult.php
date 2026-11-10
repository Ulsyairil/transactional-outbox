<?php

declare(strict_types=1);

namespace TransactionalOutbox\Core;

/**
 * Outcome of one relay cycle.
 */
final class RelayResult
{
    public function __construct(
        public readonly int $published = 0,
        public readonly int $failed = 0,
        public readonly int $deadLettered = 0,
        public readonly int $skipped = 0,
        public readonly float $durationMs = 0.0,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->published === 0 && $this->failed === 0 && $this->deadLettered === 0;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'published' => $this->published,
            'failed' => $this->failed,
            'dead_lettered' => $this->deadLettered,
            'skipped' => $this->skipped,
            'duration_ms' => round($this->durationMs, 3),
        ];
    }
}
