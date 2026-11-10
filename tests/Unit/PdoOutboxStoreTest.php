<?php

declare(strict_types=1);

namespace TransactionalOutbox\Tests\Unit;

use TransactionalOutbox\Core\OutboxConfig;
use TransactionalOutbox\Core\OutboxMessage;
use TransactionalOutbox\Core\Storage\PdoOutboxStore;
use TransactionalOutbox\Tests\TestCase;

final class PdoOutboxStoreTest extends TestCase
{
    private function store(\PDO $pdo, array $config = [], ?\TransactionalOutbox\Core\Clock $clock = null): PdoOutboxStore
    {
        $store = new PdoOutboxStore(
            $pdo,
            OutboxConfig::fromArray($config),
            $clock ?? $this->clock(),
        );
        $store->install();

        return $store;
    }

    public function testInstallIsIdempotent(): void
    {
        $pdo = $this->pdo();
        $store = $this->store($pdo);
        $store->install();
        $store->install();

        self::assertSame(0, $store->pendingCount());
    }

    public function testStoresMessage(): void
    {
        $store = $this->store($this->pdo());

        $message = $store->store(OutboxMessage::create(
            eventType: 'order.created',
            payload: ['order_id' => 42],
            aggregateType: 'order',
            aggregateId: '42',
        ));

        self::assertNotNull($message->id);
        self::assertSame(1, $store->pendingCount());
    }

    public function testDeduplicationKeyPreventsDuplicateRows(): void
    {
        $store = $this->store($this->pdo());

        $first = $store->store(OutboxMessage::create('order.created', ['id' => 1], deduplicationKey: 'order:1:created'));
        $second = $store->store(OutboxMessage::create('order.created', ['id' => 1], deduplicationKey: 'order:1:created'));

        self::assertSame($first->id, $second->id);
        self::assertSame(1, $store->pendingCount());
    }

    public function testDifferentDeduplicationKeysCreateSeparateRows(): void
    {
        $store = $this->store($this->pdo());

        $store->store(OutboxMessage::create('order.created', [], deduplicationKey: 'a'));
        $store->store(OutboxMessage::create('order.created', [], deduplicationKey: 'b'));

        self::assertSame(2, $store->pendingCount());
    }

    public function testNullDeduplicationKeyAllowsDuplicates(): void
    {
        $store = $this->store($this->pdo());

        $store->store(OutboxMessage::create('order.created'));
        $store->store(OutboxMessage::create('order.created'));

        self::assertSame(2, $store->pendingCount());
    }

    public function testClaimReturnsDueMessages(): void
    {
        $clock = $this->clock();
        $store = $this->store($this->pdo(), [], $clock);

        $store->store(OutboxMessage::create('order.created', ['id' => 1]));
        $store->store(OutboxMessage::create('order.created', ['id' => 2]));

        $claimed = $store->claim(10, 10);

        self::assertCount(2, $claimed);
        self::assertSame('order.created', $claimed[0]->eventType);
        self::assertNotNull($claimed[0]->batchId);
    }

    public function testClaimRespectsLimit(): void
    {
        $store = $this->store($this->pdo());

        $store->store(OutboxMessage::create('order.created', ['id' => 1]));
        $store->store(OutboxMessage::create('order.created', ['id' => 2]));

        self::assertCount(1, $store->claim(1, 1));
    }

    public function testClaimSkipsMessagesThatAreNotDueYet(): void
    {
        $clock = $this->clock();
        $store = $this->store($this->pdo(), [], $clock);

        $store->store(new OutboxMessage(
            eventType: 'order.created',
            payload: [],
            availableAt: $clock->now() + 60,
        ));

        self::assertCount(0, $store->claim(10, 10));
    }

    public function testClaimDoesNotReturnAlreadyClaimedMessages(): void
    {
        $store = $this->store($this->pdo());

        $store->store(OutboxMessage::create('order.created'));

        self::assertCount(1, $store->claim(10, 10));
        self::assertCount(0, $store->claim(10, 10));
    }

    public function testClaimReclaimsMessagesFromDeadWorkerAfterTimeout(): void
    {
        $clock = $this->clock();
        $store = $this->store($this->pdo(), ['claim_timeout_seconds' => 60], $clock);

        $store->store(OutboxMessage::create('order.created'));

        self::assertCount(1, $store->claim(10, 10));
        self::assertCount(0, $store->claim(10, 10));

        $clock->advance(61);

        self::assertCount(1, $store->claim(10, 10));
    }

    public function testMarkPublishedRemovesMessageFromPending(): void
    {
        $store = $this->store($this->pdo());

        $message = $store->store(OutboxMessage::create('order.created'));
        $store->markPublished((int) $message->id, 'batch-1');

        self::assertSame(0, $store->pendingCount());
        self::assertCount(0, $store->claim(10, 10));
    }

    public function testMarkFailedIncrementsAttemptsAndReschedules(): void
    {
        $clock = $this->clock();
        $store = $this->store($this->pdo(), [], $clock);

        $message = $store->store(OutboxMessage::create('order.created'));
        $store->markFailed((int) $message->id, 'boom', $clock->now() + 100);

        $reloaded = $store->findById((int) $message->id);

        self::assertNotNull($reloaded);
        self::assertSame(1, $reloaded->attempts);
        self::assertSame('boom', $reloaded->lastError);
        self::assertSame($clock->now() + 100, $reloaded->availableAt);
        self::assertCount(0, $store->claim(10, 10));
    }

    public function testDeadLetterMovesMessageOutOfPending(): void
    {
        $store = $this->store($this->pdo());

        $message = $store->store(OutboxMessage::create('order.created', ['id' => 7]));
        $store->deadLetter((int) $message->id, 'gave up');

        self::assertSame(0, $store->pendingCount());
        self::assertSame(1, $store->deadLetterCount());
    }

    public function testDeadLetterKeepsPayload(): void
    {
        $store = $this->store($this->pdo());

        $message = $store->store(OutboxMessage::create('order.created', ['id' => 7]));
        $store->deadLetter((int) $message->id, 'gave up');

        $found = $store->findByDeduplicationKey('missing');
        self::assertNull($found);
    }

    public function testPayloadSurvivesRoundTrip(): void
    {
        $store = $this->store($this->pdo());

        $message = $store->store(OutboxMessage::create(
            eventType: 'order.created',
            payload: ['nested' => ['a' => 1], 'unicode' => 'äöü'],
            headers: ['X-Test' => 'yes'],
        ));

        $reloaded = $store->findById((int) $message->id);

        self::assertNotNull($reloaded);
        self::assertSame(['nested' => ['a' => 1], 'unicode' => 'äöü'], $reloaded->payload);
        self::assertSame(['X-Test' => 'yes'], $reloaded->headers);
    }

    public function testStatusReportsCounts(): void
    {
        $store = $this->store($this->pdo());

        $store->store(OutboxMessage::create('order.created'));

        $status = $store->status();

        self::assertSame(1, $status['pending']);
        self::assertSame(0, $status['dead_lettered']);
        self::assertSame('sqlite', $status['driver']);
    }
}
