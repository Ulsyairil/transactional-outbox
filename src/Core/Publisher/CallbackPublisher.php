<?php

declare(strict_types=1);

namespace TransactionalOutbox\Core\Publisher;

use TransactionalOutbox\Core\OutboxMessage;
use TransactionalOutbox\Core\Publisher;

/**
 * Hands the message to a callable.
 *
 * The simplest publisher: wrap any function that does the real work.
 */
final class CallbackPublisher implements Publisher
{
    /**
     * @param callable(OutboxMessage): void $callback
     */
    public function __construct(private $callback)
    {
    }

    public function publish(OutboxMessage $message): void
    {
        ($this->callback)($message);
    }
}
