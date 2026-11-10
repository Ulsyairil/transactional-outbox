<?php

declare(strict_types=1);

namespace TransactionalOutbox\Adapter\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use TransactionalOutbox\Core\Outbox;

/**
 * @method static \TransactionalOutbox\Core\OutboxMessage publish(string $eventType, array<string, mixed> $payload = [], ?string $aggregateType = null, ?string $aggregateId = null, ?string $deduplicationKey = null, array<string, string> $headers = [])
 * @method static \TransactionalOutbox\Core\OutboxMessage store(\TransactionalOutbox\Core\OutboxMessage $message)
 * @method static mixed transaction(callable $work, callable $message)
 * @method static int pendingCount()
 * @method static int deadLetterCount()
 * @method static array<string, mixed> status()
 *
 * @see Outbox
 */
final class OutboxFacade extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return Outbox::class;
    }
}
