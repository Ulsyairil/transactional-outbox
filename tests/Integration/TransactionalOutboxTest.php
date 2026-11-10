<?php

declare(strict_types=1);

namespace TransactionalOutbox\Tests\Integration;

use RuntimeException;
use TransactionalOutbox\Core\Outbox;
use TransactionalOutbox\Core\OutboxConfig;
use TransactionalOutbox\Core\OutboxMessage;
use TransactionalOutbox\Core\OutboxStore;
use TransactionalOutbox\Core\Storage\PdoOutboxStore;
use TransactionalOutbox\Tests\TestCase;

/**
 * The atomicity guarantee: the message and the business write commit
 * together, or neither survives.
 */
final class TransactionalOutboxTest extends TestCase
{
    private function outbox(\PDO $pdo): Outbox
    {
        $config = OutboxConfig::fromArray();
        $store = new PdoOutboxStore($pdo, $config, $this->clock());
        $store->install();

        return new Outbox($store, $pdo);
    }

    public function testMessageAndBusinessWriteCommitTogether(): void
    {
        $pdo = $this->pdo();
        $pdo->exec('CREATE TABLE orders (id INTEGER PRIMARY KEY, total INTEGER NOT NULL)');

        $outbox = $this->outbox($pdo);

        $outbox->transaction(
            function () use ($pdo): int {
                $pdo->exec('INSERT INTO orders (id, total) VALUES (1, 100)');

                return 1;
            },
            static fn (): OutboxMessage => OutboxMessage::create('order.created', ['order_id' => 1]),
        );

        self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn());
        self::assertSame(1, $outbox->pendingCount());
    }

    public function testRolledBackBusinessWriteAlsoRemovesTheMessage(): void
    {
        $pdo = $this->pdo();
        $pdo->exec('CREATE TABLE orders (id INTEGER PRIMARY KEY, total INTEGER NOT NULL)');

        $outbox = $this->outbox($pdo);

        try {
            $outbox->transaction(
                function () use ($pdo): void {
                    $pdo->exec('INSERT INTO orders (id, total) VALUES (1, 100)');

                    throw new RuntimeException('business rule failed');
                },
                static fn (): OutboxMessage => OutboxMessage::create('order.created', ['order_id' => 1]),
            );

            self::fail('The exception should bubble up.');
        } catch (RuntimeException $e) {
            self::assertSame('business rule failed', $e->getMessage());
        }

        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn());
        self::assertSame(0, $outbox->pendingCount());
    }

    public function testFailedMessageStoreRollsBackTheBusinessWrite(): void
    {
        $pdo = $this->pdo();
        $pdo->exec('CREATE TABLE orders (id INTEGER PRIMARY KEY, total INTEGER NOT NULL)');

        $outbox = $this->outbox($pdo);

        try {
            $outbox->transaction(
                function () use ($pdo): void {
                    $pdo->exec('INSERT INTO orders (id, total) VALUES (1, 100)');
                },
                static fn (): OutboxMessage => throw new RuntimeException('cannot store message'),
            );

            self::fail('The exception should bubble up.');
        } catch (RuntimeException $e) {
            self::assertSame('cannot store message', $e->getMessage());
        }

        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn());
        self::assertSame(0, $outbox->pendingCount());
    }

    public function testTransactionReturnsTheWorkResult(): void
    {
        $pdo = $this->pdo();
        $outbox = $this->outbox($pdo);

        $result = $outbox->transaction(
            static fn (): string => 'order-1',
            static fn (): OutboxMessage => OutboxMessage::create('order.created'),
        );

        self::assertSame('order-1', $result);
    }

    public function testTransactionJoinsAnExistingTransaction(): void
    {
        $pdo = $this->pdo();
        $pdo->exec('CREATE TABLE orders (id INTEGER PRIMARY KEY)');

        $outbox = $this->outbox($pdo);

        $pdo->beginTransaction();

        $outbox->transaction(
            function () use ($pdo): void {
                $pdo->exec('INSERT INTO orders (id) VALUES (1)');
            },
            static fn (): OutboxMessage => OutboxMessage::create('order.created'),
        );

        // The outer transaction still owns the commit.
        self::assertTrue($pdo->inTransaction());

        $pdo->rollBack();

        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn());
        self::assertSame(0, $outbox->pendingCount());
    }

    public function testStoreOutsideATransactionIsStillPersisted(): void
    {
        $pdo = $this->pdo();
        $outbox = $this->outbox($pdo);

        $message = $outbox->publish('order.created', ['order_id' => 9]);

        self::assertNotNull($message->id);
        self::assertSame(1, $outbox->pendingCount());
    }
}
