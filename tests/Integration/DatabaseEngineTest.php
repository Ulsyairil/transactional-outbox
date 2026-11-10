<?php

declare(strict_types=1);

namespace TransactionalOutbox\Tests\Integration;

use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use TransactionalOutbox\Core\DatabaseRelay;
use TransactionalOutbox\Core\OutboxConfig;
use TransactionalOutbox\Core\OutboxMessage;
use TransactionalOutbox\Core\Publisher;
use TransactionalOutbox\Core\Storage\PdoOutboxStore;
use TransactionalOutbox\Tests\TestCase;

/**
 * The same behaviour on MySQL and PostgreSQL.
 *
 * The test is skipped unless the DSN is provided, so the suite still runs on a
 * machine without those servers. CI sets the environment variables.
 */
final class DatabaseEngineTest extends TestCase
{
    /**
     * @return array<string, array{dsn: string, user: string, password: string, driver: string}>
     */
    public static function engines(): array
    {
        return [
            'mysql' => [
                'dsn' => (string) (getenv('OUTBOX_TEST_MYSQL_DSN') ?: ''),
                'user' => (string) (getenv('OUTBOX_TEST_MYSQL_USER') ?: 'root'),
                'password' => (string) (getenv('OUTBOX_TEST_MYSQL_PASSWORD') ?: 'root'),
                'driver' => 'mysql',
            ],
            'pgsql' => [
                'dsn' => (string) (getenv('OUTBOX_TEST_PGSQL_DSN') ?: ''),
                'user' => (string) (getenv('OUTBOX_TEST_PGSQL_USER') ?: 'postgres'),
                'password' => (string) (getenv('OUTBOX_TEST_PGSQL_PASSWORD') ?: 'postgres'),
                'driver' => 'pgsql',
            ],
        ];
    }

    #[DataProvider('engines')]
    public function testInstallsTheSchema(string $dsn, string $user, string $password, string $driver): void
    {
        $pdo = $this->connect($dsn, $user, $password, $driver);

        $store = new PdoOutboxStore($pdo, OutboxConfig::fromArray(), $this->clock());
        $store->install();
        $store->install();

        self::assertSame($driver, $store->status()['driver']);
    }

    #[DataProvider('engines')]
    public function testStoresAndRelaysAMessage(string $dsn, string $user, string $password, string $driver): void
    {
        $pdo = $this->connect($dsn, $user, $password, $driver);

        $config = OutboxConfig::fromArray([
            'table' => 'outbox_messages_test',
            'batch_table' => 'outbox_batches_test',
            'dead_letter_table' => 'outbox_dead_letters_test',
        ]);

        $store = new PdoOutboxStore($pdo, $config, $this->clock());
        $store->install();

        $published = [];

        $relay = new DatabaseRelay(
            $store,
            $this->publisher($published),
            $config,
            $this->clock(),
        );

        $store->store(OutboxMessage::create('order.created', ['id' => 1], deduplicationKey: 'engine:' . $driver));
        $store->store(OutboxMessage::create('order.created', ['id' => 1], deduplicationKey: 'engine:' . $driver));

        self::assertSame(1, $store->pendingCount(), 'The deduplication key must collapse the duplicate.');

        $result = $relay->run();

        self::assertSame(1, $result->published);
        self::assertCount(1, $published);
        self::assertSame(0, $store->pendingCount());

        $store->uninstall();
    }

    private function connect(string $dsn, string $user, string $password, string $driver): PDO
    {
        if ($dsn === '') {
            self::markTestSkipped(sprintf('No DSN configured for %s.', $driver));
        }

        $pdo = new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);

        return $pdo;
    }

    /**
     * @param list<string> $published
     */
    private function publisher(array &$published): Publisher
    {
        return new class ($published) implements Publisher {
            /**
             * @param list<string> $published
             */
            public function __construct(private array &$published)
            {
            }

            public function publish(OutboxMessage $message): void
            {
                $this->published[] = $message->eventType;
            }
        };
    }
}
