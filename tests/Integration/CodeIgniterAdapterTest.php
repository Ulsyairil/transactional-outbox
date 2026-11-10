<?php

declare(strict_types=1);

namespace TransactionalOutbox\Tests\Integration;

use PDO;
use TransactionalOutbox\Adapter\CodeIgniter4\Config\Outbox as CodeIgniterOutboxConfig;
use TransactionalOutbox\Adapter\CodeIgniter4\Outbox as CodeIgniterOutbox;
use TransactionalOutbox\Core\Publisher\OutboxEvent;
use TransactionalOutbox\Tests\TestCase;

/**
 * The CodeIgniter 4 adapter must build a working outbox from a plain PDO
 * connection, without needing a bootstrapped framework.
 */
final class CodeIgniterAdapterTest extends TestCase
{
    public function testBuildsAnOutboxFromAPdoConnection(): void
    {
        $pdo = $this->pdo();

        $settings = new CodeIgniterOutboxConfig();
        $settings->pdo = $pdo;

        $outbox = CodeIgniterOutbox::make($settings)->outbox();

        $outbox->publish('order.created', ['order_id' => 1]);

        self::assertSame(1, $outbox->pendingCount());

        $statement = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'outbox_messages'");
        self::assertSame('outbox_messages', $statement->fetchColumn());
    }

    public function testRelayDeliversThroughTheConfiguredPublisher(): void
    {
        $pdo = $this->pdo();

        $settings = new CodeIgniterOutboxConfig();
        $settings->pdo = $pdo;
        $settings->publisher = new RecordingPublisher();

        $adapter = CodeIgniterOutbox::make($settings);

        $adapter->outbox()->publish('order.created', ['order_id' => 1]);

        $result = $adapter->relay()->run();

        self::assertSame(1, $result->published);
        self::assertSame(0, $adapter->outbox()->pendingCount());
    }

    public function testConfigArrayMatchesTheCoreDefaults(): void
    {
        $config = CodeIgniterOutbox::toArray(new CodeIgniterOutboxConfig());

        self::assertSame('outbox_messages', $config['table']);
        self::assertSame(5, $config['max_attempts']);
        self::assertSame(100, $config['batch_size']);
        self::assertTrue($config['auto_install']);
    }
}

/**
 * Publisher that records the events it receives.
 */
final class RecordingPublisher implements \TransactionalOutbox\Core\Publisher
{
    /** @var list<OutboxEvent> */
    public array $events = [];

    public function publish(\TransactionalOutbox\Core\OutboxMessage $message): void
    {
        $this->events[] = new OutboxEvent(
            eventType: $message->eventType,
            payload: $message->payload,
        );
    }
}
