<?php

declare(strict_types=1);

namespace TransactionalOutbox\Core\Publisher;

use TransactionalOutbox\Core\Exception\PublishFailedException;
use TransactionalOutbox\Core\OutboxMessage;
use TransactionalOutbox\Core\Publisher;

/**
 * Publishes to a PSR-14 event dispatcher.
 *
 * The dispatcher is duck-typed: any object with a `dispatch()` method works,
 * so Laravel's bus, Symfony's messenger-bridge or a plain PSR-14 dispatcher can
 * be plugged in without an adapter.
 */
final class Psr14Publisher implements Publisher
{
    /**
     * @var callable(object): mixed
     */
    private $dispatcher;

    /**
     * @param callable(object): mixed $dispatcher
     */
    public function __construct(callable $dispatcher)
    {
        $this->dispatcher = $dispatcher;
    }

    /**
     * Builds a publisher from a dispatcher object.
     *
     * The dispatcher is duck-typed: any object with a `dispatch()` method
     * works, so a PSR-14 dispatcher and a framework bus both qualify.
     */
    public static function fromDispatcher(object $dispatcher): self
    {
        if (! method_exists($dispatcher, 'dispatch')) {
            throw new \InvalidArgumentException(sprintf(
                'The dispatcher must have a dispatch() method, %s does not.',
                $dispatcher::class,
            ));
        }

        return new self(static fn (object $event): mixed => $dispatcher->dispatch($event));
    }

    public function publish(OutboxMessage $message): void
    {
        $event = new OutboxEvent(
            eventType: $message->eventType,
            payload: $message->payload,
            aggregateType: $message->aggregateType,
            aggregateId: $message->aggregateId,
            deduplicationKey: $message->deduplicationKey,
            headers: $message->headers,
        );

        try {
            ($this->dispatcher)($event);
        } catch (\Throwable $e) {
            throw new PublishFailedException(
                sprintf('Event dispatcher rejected "%s": %s', $message->eventType, $e->getMessage()),
                $message->eventType,
                0,
                $e,
            );
        }
    }
}
