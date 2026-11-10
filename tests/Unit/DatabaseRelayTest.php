<?php

declare(strict_types=1);

namespace TransactionalOutbox\Tests\Unit;

use RuntimeException;
use TransactionalOutbox\Core\DatabaseRelay;
use TransactionalOutbox\Core\OutboxConfig;
use TransactionalOutbox\Core\OutboxMessage;
use TransactionalOutbox\Core\Publisher;
use TransactionalOutbox\Core\Storage\PdoOutboxStore;
use TransactionalOutbox\Tests\FakeClock;
use TransactionalOutbox\Tests\TestCase;

final class DatabaseRelayTest extends TestCase
{
    /**
     * Publisher that records what it delivered and can be told to fail.
     */
    private function publisher(array $failures = []): Publisher
    {
        return new class ($failures) implements Publisher {
            /** @var list<string> */
            public array $published = [];

            /** @var list<string> */
            private array $failures;

            /**
             * @param list<string> $failures
             */
            public function __construct(array $failures)
            {
                $this->failures = $failures;
            }

            public function publish(OutboxMessage $message): void
            {
                if ($this->failures !== []) {
                    throw new RuntimeException(array_shift($this->failures));
                }

                $this->published[] = $message->eventType;
            }
        };
    }

    private function relay(\PDO $pdo, Publisher $publisher, array $config = [], ?FakeClock $clock = null): DatabaseRelay
    {
        $config = OutboxConfig::fromArray($config);
        $store = new PdoOutboxStore($pdo, $config, $clock ?? $this->clock());
        $store->install();

        return new DatabaseRelay($store, $publisher, $config, $clock ?? $this->clock());
    }

    public function testPublishesPendingMessages(): void
    {
        $pdo = $this->pdo();
        $clock = $this->clock();
        $publisher = $this->publisher();
        $relay = $this->relay($pdo, $publisher, [], $clock);

        $store = new PdoOutboxStore($pdo, OutboxConfig::fromArray(), $clock);
        $store->install();
        $store->store(OutboxMessage::create('order.created', ['id' => 1]));
        $store->store(OutboxMessage::create('order.created', ['id' => 2]));

        $result = $relay->run();

        self::assertSame(2, $result->published);
        self::assertSame(0, $result->failed);
        self::assertCount(2, $publisher->published);
        self::assertSame(0, $store->pendingCount());
    }

    public function testEmptyOutboxIsANoop(): void
    {
        $pdo = $this->pdo();
        $relay = $this->relay($pdo, $this->publisher());

        $result = $relay->run();

        self::assertSame(0, $result->published);
        self::assertTrue($result->isEmpty());
    }

    public function testFailedPublishSchedulesRetry(): void
    {
        $pdo = $this->pdo();
        $clock = $this->clock();
        $relay = $this->relay($pdo, $this->publisher(['broker down']), ['base_delay_seconds' => 60], $clock);

        $store = new PdoOutboxStore($pdo, OutboxConfig::fromArray(['base_delay_seconds' => 60]), $clock);
        $store->install();
        $message = $store->store(OutboxMessage::create('order.created'));

        $result = $relay->run();

        self::assertSame(0, $result->published);
        self::assertSame(1, $result->failed);
        self::assertSame(0, $result->deadLettered);

        $reloaded = $store->findById((int) $message->id);
        self::assertNotNull($reloaded);
        self::assertSame(1, $reloaded->attempts);
        self::assertSame('broker down', $reloaded->lastError);
        self::assertSame($clock->now() + 60, $reloaded->availableAt);
    }

    public function testMessageIsDeadLetteredAfterMaxAttempts(): void
    {
        $pdo = $this->pdo();
        $clock = $this->clock();
        $relay = $this->relay($pdo, $this->publisher(['a', 'b', 'c']), ['max_attempts' => 3], $clock);

        $store = new PdoOutboxStore($pdo, OutboxConfig::fromArray(['max_attempts' => 3]), $clock);
        $store->install();
        $message = $store->store(OutboxMessage::create('order.created'));

        for ($i = 0; $i < 3; $i++) {
            $relay->run();
            $clock->advance(100_000);
        }

        self::assertNull($store->findById((int) $message->id));
        self::assertSame(1, $store->deadLetterCount());
    }

    public function testDeadLetteredCountIsReported(): void
    {
        $pdo = $this->pdo();
        $clock = $this->clock();
        $relay = $this->relay($pdo, $this->publisher(['x']), ['max_attempts' => 1], $clock);

        $store = new PdoOutboxStore($pdo, OutboxConfig::fromArray(['max_attempts' => 1]), $clock);
        $store->install();
        $store->store(OutboxMessage::create('order.created'));

        $result = $relay->run();

        self::assertSame(1, $result->failed);
        self::assertSame(1, $result->deadLettered);
    }

    public function testBackoffGrowsExponentially(): void
    {
        $pdo = $this->pdo();
        $relay = $this->relay($pdo, $this->publisher(), [
            'base_delay_seconds' => 10,
            'max_delay_seconds' => 100,
        ]);

        self::assertSame(10, $relay->backoffSeconds(1));
        self::assertSame(20, $relay->backoffSeconds(2));
        self::assertSame(40, $relay->backoffSeconds(3));
        self::assertSame(80, $relay->backoffSeconds(4));
        self::assertSame(100, $relay->backoffSeconds(5));
    }

    public function testRetryHappensAfterTheDelay(): void
    {
        $pdo = $this->pdo();
        $clock = $this->clock();
        $publisher = $this->publisher(['temporary']);
        $relay = $this->relay($pdo, $publisher, ['base_delay_seconds' => 30], $clock);

        $store = new PdoOutboxStore($pdo, OutboxConfig::fromArray(['base_delay_seconds' => 30]), $clock);
        $store->install();
        $store->store(OutboxMessage::create('order.created'));

        $relay->run();
        self::assertSame(0, $relay->run()->published);

        $clock->advance(31);

        self::assertSame(1, $relay->run()->published);
        self::assertCount(1, $publisher->published);
    }
}
