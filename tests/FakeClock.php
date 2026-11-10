<?php

declare(strict_types=1);

namespace TransactionalOutbox\Tests;

use TransactionalOutbox\Core\Clock;

/**
 * Clock with a fixed, movable time.
 */
final class FakeClock implements Clock
{
    public function __construct(private int $now = 1_700_000_000)
    {
    }

    public function now(): int
    {
        return $this->now;
    }

    public function advance(int $seconds): void
    {
        $this->now += $seconds;
    }

    public function set(int $timestamp): void
    {
        $this->now = $timestamp;
    }
}
