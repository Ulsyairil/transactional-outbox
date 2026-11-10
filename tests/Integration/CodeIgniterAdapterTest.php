<?php

declare(strict_types=1);

namespace TransactionalOutbox\Tests\Integration;

use PDO;
use TransactionalOutbox\Adapter\CodeIgniter4\Config\Outbox as CodeIgniterOutboxConfig;
use TransactionalOutbox\Adapter\CodeIgniter4\Outbox as CodeIgniterOutbox;
use TransactionalOutbox\Core\Outbox;
use TransactionalOutbox\Tests\TestCase;

/**
 * The CodeIgniter 4 adapter must build a PDO connection from the framework
 * database config, because CodeIgniter's own drivers do not expose one.
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

    public function testTheDsnIsBuiltPerDriver(): void
    {
        self::assertSame('sqlite:/tmp/app.db', CodeIgniterOutbox::dsn([
            'DBDriver' => 'SQLite3',
            'database' => '/tmp/app.db',
        ]));

        self::assertSame('mysql:host=db;port=3306;dbname=app;charset=utf8mb4', CodeIgniterOutbox::dsn([
            'DBDriver' => 'MySQLi',
            'hostname' => 'db',
            'database' => 'app',
            'charset' => 'utf8mb4',
        ]));

        self::assertSame('pgsql:host=db;port=5432;dbname=app', CodeIgniterOutbox::dsn([
            'DBDriver' => 'Postgre',
            'hostname' => 'db',
            'database' => 'app',
        ]));

        // A ready-made DSN wins.
        self::assertSame('mysql:host=other;dbname=app', CodeIgniterOutbox::dsn([
            'DBDriver' => 'MySQLi',
            'DSN' => 'mysql:host=other;dbname=app',
            'database' => 'app',
        ]));
    }

    public function testAnUnknownConnectionGroupFailsLoudly(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('The database connection "missing" is not configured.');

        CodeIgniterOutbox::databaseConfig('missing');
    }

    public function testTheOutboxIsTheSameInstanceAcrossCalls(): void
    {
        $settings = new CodeIgniterOutboxConfig();
        $settings->pdo = $this->pdo();

        self::assertInstanceOf(Outbox::class, CodeIgniterOutbox::make($settings)->outbox());
    }
}

/**
 * Publisher that records the events it receives.
 */
final class RecordingPublisher implements \TransactionalOutbox\Core\Publisher
{
    /** @var list<\TransactionalOutbox\Core\Publisher\OutboxEvent> */
    public array $events = [];

    public function publish(\TransactionalOutbox\Core\OutboxMessage $message): void
    {
        $this->events[] = new \TransactionalOutbox\Core\Publisher\OutboxEvent(
            eventType: $message->eventType,
            payload: $message->payload,
        );
    }
}
