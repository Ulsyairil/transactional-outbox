<?php

declare(strict_types=1);

namespace TransactionalOutbox\Tests\Integration;

use TransactionalOutbox\Core\DatabaseRelay;
use TransactionalOutbox\Core\OutboxConfig;
use TransactionalOutbox\Core\OutboxMessage;
use TransactionalOutbox\Core\Publisher;
use TransactionalOutbox\Core\Storage\PdoOutboxStore;
use TransactionalOutbox\Tests\TestCase;

/**
 * The batch table records one row per relay cycle, so a delivery can be
 * traced back to the cycle that sent it.
 */
final class BatchTrackingTest extends TestCase
{
    public function testTheRelayRecordsOneBatchPerCycle(): void
    {
        $pdo = $this->pdo();
        $clock = $this->clock();
        $config = OutboxConfig::fromArray();

        $store = new PdoOutboxStore($pdo, $config, $clock);
        $store->install();

        $store->store(OutboxMessage::create('order.created', ['id' => 1]));
        $store->store(OutboxMessage::create('order.created', ['id' => 2]));

        $relay = new DatabaseRelay($store, new class implements Publisher {
            public function publish(OutboxMessage $message): void
            {
            }
        }, $config, $clock);

        $relay->run();

        $statement = $pdo->query('SELECT id, message_count, created_at, completed_at FROM outbox_batches');
        $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);

        self::assertCount(1, $rows);
        self::assertSame(2, (int) $rows[0]['message_count']);
        self::assertNotNull($rows[0]['completed_at']);
    }

    public function testAnEmptyCycleRecordsNothing(): void
    {
        $pdo = $this->pdo();
        $clock = $this->clock();
        $config = OutboxConfig::fromArray();

        $store = new PdoOutboxStore($pdo, $config, $clock);
        $store->install();

        $relay = new DatabaseRelay($store, new class implements Publisher {
            public function publish(OutboxMessage $message): void
            {
            }
        }, $config, $clock);

        $relay->run();

        $statement = $pdo->query('SELECT COUNT(*) FROM outbox_batches');

        self::assertSame(0, (int) $statement->fetchColumn());
    }
}
