<?php

declare(strict_types=1);

namespace TransactionalOutbox\Core;

/**
 * Publishes a stored message to the outside world.
 *
 * The publisher is the only place that talks to a broker, an HTTP endpoint or
 * another process. The core never leaves the database on its own.
 */
interface Publisher
{
    /**
     * @throws \TransactionalOutbox\Core\Exception\PublishFailedException On delivery failure.
     */
    public function publish(OutboxMessage $message): void;
}
