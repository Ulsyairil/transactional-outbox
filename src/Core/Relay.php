<?php

declare(strict_types=1);

namespace TransactionalOutbox\Core;

/**
 * Runs the pending messages of one cycle.
 *
 * Returning normally means the message was delivered. Throwing means it was
 * not: the outbox retries it with backoff and finally dead-letters it.
 */
interface Relay
{
    /**
     * @return \TransactionalOutbox\Core\RelayResult
     */
    public function run(): RelayResult;
}
