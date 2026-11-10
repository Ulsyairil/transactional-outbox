<?php

declare(strict_types=1);

/**
 * End-to-end demo of the transactional outbox.
 *
 * Run it with:
 *
 *   php examples/demo.php
 *
 * It creates a throwaway SQLite database, writes an order and an event in one
 * transaction, runs the relay and prints what happened. Nothing leaves the
 * machine.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use TransactionalOutbox\Core\DatabaseRelay;
use TransactionalOutbox\Core\OutboxConfig;
use TransactionalOutbox\Core\OutboxFactory;
use TransactionalOutbox\Core\OutboxMessage;
use TransactionalOutbox\Core\Publisher\OutboxEvent;
use TransactionalOutbox\Core\Publisher\Psr14Publisher;
use TransactionalOutbox\Core\Storage\PdoOutboxStore;

$file = sys_get_temp_dir() . '/transactional-outbox-demo-' . bin2hex(random_bytes(3)) . '.sqlite';

$pdo = new PDO('sqlite:' . $file);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE orders (id INTEGER PRIMARY KEY, total INTEGER NOT NULL)');

$config = OutboxConfig::fromArray();
$store = new PdoOutboxStore($pdo, $config, new TransactionalOutbox\Core\SystemClock());
$store->install();

$outbox = OutboxFactory::fromConnection($pdo);

// 1. The business write and the event commit together.
$outbox->transaction(
    static function () use ($pdo): void {
        $pdo->exec('INSERT INTO orders (id, total) VALUES (1, 100)');
    },
    static fn (): OutboxMessage => OutboxMessage::create(
        eventType: 'order.created',
        payload: ['order_id' => 1, 'total' => 100],
        aggregateType: 'order',
        aggregateId: '1',
        deduplicationKey: 'order:1:created',
    ),
);

echo 'orders written:    ' . (int) $pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn() . PHP_EOL;
echo 'messages pending:  ' . $outbox->pendingCount() . PHP_EOL;

// 2. The same event with the same deduplication key is not stored twice.
$outbox->publish(
    eventType: 'order.created',
    payload: ['order_id' => 1, 'total' => 100],
    deduplicationKey: 'order:1:created',
);

echo 'after a duplicate: ' . $outbox->pendingCount() . PHP_EOL;

// 3. A failed transaction leaves neither the order nor the message behind.
try {
    $outbox->transaction(
        static function () use ($pdo): void {
            $pdo->exec('INSERT INTO orders (id, total) VALUES (2, 50)');

            throw new RuntimeException('the business rule failed');
        },
        static fn (): OutboxMessage => OutboxMessage::create('order.created', ['order_id' => 2]),
    );
} catch (RuntimeException $e) {
    echo 'rolled back:       ' . $e->getMessage() . PHP_EOL;
}

echo 'orders written:    ' . (int) $pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn() . PHP_EOL;
echo 'messages pending:  ' . $outbox->pendingCount() . PHP_EOL;

// 4. The relay delivers the message to a dispatcher.
$dispatcher = new class {
    /** @var list<OutboxEvent> */
    public array $events = [];

    public function dispatch(object $event): object
    {
        if ($event instanceof OutboxEvent) {
            $this->events[] = $event;

            echo 'delivered:         ' . $event->eventType . ' ' . json_encode($event->payload) . PHP_EOL;
        }

        return $event;
    }
};

$relay = new DatabaseRelay(
    $store,
    Psr14Publisher::fromDispatcher($dispatcher),
    $config,
    new TransactionalOutbox\Core\SystemClock(),
);

$result = $relay->run();

echo 'relay result:      ' . json_encode($result->toArray()) . PHP_EOL;
echo 'messages pending:  ' . $outbox->pendingCount() . PHP_EOL;
echo 'dead letters:      ' . $outbox->deadLetterCount() . PHP_EOL;

// 5. Every cycle leaves a trace in the batch table.
$batches = $pdo->query('SELECT id, message_count, created_at, completed_at FROM outbox_batches')->fetchAll(PDO::FETCH_ASSOC);

echo 'batches recorded:  ' . count($batches) . PHP_EOL;

unlink($file);
