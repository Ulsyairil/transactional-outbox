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

    #[DataProvider('engines')]
    public function testTheDeduplicationIndexExists(string $dsn, string $user, string $password, string $driver): void
    {
        $pdo = $this->connect($dsn, $user, $password, $driver);

        $store = new PdoOutboxStore($pdo, OutboxConfig::fromArray([
            'table' => 'outbox_messages_idx_test',
            'batch_table' => 'outbox_batches_idx_test',
            'dead_letter_table' => 'outbox_dead_letters_idx_test',
        ]), $this->clock());
        $store->install();

        self::assertSame(
            1,
            $this->countIndexes($pdo, 'outbox_messages_idx_test', 'outbox_messages_idx_test_dedup_uq'),
            'The unique index on deduplication_key is required by the upsert.',
        );

        $store->uninstall();
    }

    /**
     * Two outbox tables in one schema must not share index names.
     *
     * PostgreSQL and SQLite scope index names per schema, so a fixed index
     * name made the second install fail.
     */
    #[DataProvider('engines')]
    public function testTwoOutboxTablesCanCoexistInOneSchema(string $dsn, string $user, string $password, string $driver): void
    {
        $pdo = $this->connect($dsn, $user, $password, $driver);

        $first = new PdoOutboxStore($pdo, OutboxConfig::fromArray([
            'table' => 'outbox_messages_a',
            'batch_table' => 'outbox_batches_a',
            'dead_letter_table' => 'outbox_dead_letters_a',
        ]), $this->clock());
        $first->install();

        $second = new PdoOutboxStore($pdo, OutboxConfig::fromArray([
            'table' => 'outbox_messages_b',
            'batch_table' => 'outbox_batches_b',
            'dead_letter_table' => 'outbox_dead_letters_b',
        ]), $this->clock());
        $second->install();

        $first->store(OutboxMessage::create('order.created', ['id' => 1], deduplicationKey: 'shared'));
        $second->store(OutboxMessage::create('order.created', ['id' => 1], deduplicationKey: 'shared'));

        self::assertSame(1, $first->pendingCount());
        self::assertSame(1, $second->pendingCount());

        $first->uninstall();
        $second->uninstall();
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

    private function countIndexes(PDO $pdo, string $table, string $index): int
    {
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'mysql') {
            $statement = $pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.statistics
                 WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?',
            );
        } else {
            $statement = $pdo->prepare(
                'SELECT COUNT(*) FROM pg_indexes WHERE tablename = ? AND indexname = ?',
            );
        }

        $statement->execute([$table, $index]);

        return (int) $statement->fetchColumn();
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
