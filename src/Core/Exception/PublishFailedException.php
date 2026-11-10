<?php

declare(strict_types=1);

namespace TransactionalOutbox\Core\Exception;

final class PublishFailedException extends OutboxException
{
    public function __construct(
        string $message,
        public readonly string $eventType = '',
        public readonly int $attempts = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
