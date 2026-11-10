<?php

declare(strict_types=1);

namespace TransactionalOutbox\Tests\Unit;

use TransactionalOutbox\Core\Exception\PublishFailedException;
use TransactionalOutbox\Core\OutboxMessage;
use TransactionalOutbox\Core\Publisher\CallbackPublisher;
use TransactionalOutbox\Core\Publisher\HttpPublisher;
use TransactionalOutbox\Core\Publisher\LogPublisher;
use TransactionalOutbox\Core\Publisher\OutboxEvent;
use TransactionalOutbox\Core\Publisher\Psr14Publisher;
use TransactionalOutbox\Tests\TestCase;

final class PublisherTest extends TestCase
{
    public function testCallbackPublisherReceivesTheMessage(): void
    {
        $received = null;

        $publisher = new CallbackPublisher(static function (OutboxMessage $message) use (&$received): void {
            $received = $message;
        });

        $publisher->publish(OutboxMessage::create('order.created', ['id' => 1]));

        self::assertInstanceOf(OutboxMessage::class, $received);
        self::assertSame('order.created', $received->eventType);
    }

    public function testLogPublisherWritesOneJsonLine(): void
    {
        $stream = fopen('php://memory', 'r+b');

        $publisher = new LogPublisher($stream);
        $publisher->publish(OutboxMessage::create('order.created', ['id' => 1]));

        rewind($stream);
        $contents = (string) stream_get_contents($stream);

        self::assertStringContainsString('"event_type":"order.created"', $contents);
        self::assertSame(1, substr_count(trim($contents), "\n") + 1);
    }

    public function testPsr14PublisherDispatchesAnEvent(): void
    {
        $dispatched = [];

        $publisher = Psr14Publisher::fromDispatcher(new class ($dispatched) {
            /**
             * @param list<object> $dispatched
             */
            public function __construct(private array &$dispatched)
            {
            }

            public function dispatch(object $event): object
            {
                $this->dispatched[] = $event;

                return $event;
            }
        });

        $publisher->publish(OutboxMessage::create('order.created', ['id' => 1]));

        self::assertCount(1, $dispatched);
        self::assertInstanceOf(OutboxEvent::class, $dispatched[0]);
        self::assertSame('order.created', $dispatched[0]->eventType);
        self::assertSame(['id' => 1], $dispatched[0]->payload);
    }

    public function testPsr14PublisherWrapsDispatcherFailures(): void
    {
        $publisher = Psr14Publisher::fromDispatcher(new class {
            public function dispatch(object $event): object
            {
                throw new \RuntimeException('bus is down');
            }
        });

        $this->expectException(PublishFailedException::class);
        $this->expectExceptionMessage('bus is down');

        $publisher->publish(OutboxMessage::create('order.created'));
    }

    public function testHttpPublisherAcceptsTwoHundred(): void
    {
        $publisher = new HttpPublisher(static fn (): array => ['status' => 200]);

        $publisher->publish(OutboxMessage::create('order.created'));

        self::assertTrue(true);
    }

    public function testHttpPublisherRejectsServerErrors(): void
    {
        $publisher = new HttpPublisher(static fn (): array => ['status' => 500]);

        $this->expectException(PublishFailedException::class);
        $this->expectExceptionMessage('status 500');

        $publisher->publish(OutboxMessage::create('order.created'));
    }

    public function testHttpPublisherSendsTheEventTypeHeader(): void
    {
        $headers = [];

        $publisher = new HttpPublisher(static function (OutboxMessage $message, array $sent) use (&$headers): array {
            $headers = $sent;

            return ['status' => 204];
        });

        $publisher->publish(OutboxMessage::create('order.created', [], deduplicationKey: 'order:1'));

        self::assertSame('application/json', $headers['Content-Type']);
        self::assertSame('order.created', $headers['X-Outbox-Event-Type']);
        self::assertSame('order:1', $headers['X-Outbox-Deduplication-Key']);
    }
}
