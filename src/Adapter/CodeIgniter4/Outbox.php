<?php

declare(strict_types=1);

namespace TransactionalOutbox\Adapter\CodeIgniter4;

use PDO;
use TransactionalOutbox\Adapter\CodeIgniter4\Config\Outbox as OutboxConfig;
use TransactionalOutbox\Core\DatabaseRelay;
use TransactionalOutbox\Core\Outbox as CoreOutbox;
use TransactionalOutbox\Core\OutboxFactory;
use TransactionalOutbox\Core\Publisher;
use TransactionalOutbox\Core\Publisher\LogPublisher;

/**
 * Convenience factory for CodeIgniter 4.
 */
final class Outbox
{
    private static ?self $instance = null;

    private readonly CoreOutbox $outbox;

    private readonly DatabaseRelay $relay;

    private function __construct(OutboxConfig $settings)
    {
        $config = self::toArray($settings);

        $this->outbox = OutboxFactory::fromConnection(self::pdo($settings), $config);
        $this->relay = OutboxFactory::relayFrom(
            $this->outbox->storeInstance(),
            self::publisher($settings),
            $config,
        );
    }

    public static function make(?OutboxConfig $settings = null): self
    {
        if ($settings === null && self::$instance !== null) {
            return self::$instance;
        }

        $instance = new self($settings ?? self::settings());

        if ($settings === null) {
            self::$instance = $instance;
        }

        return $instance;
    }

    public function outbox(): CoreOutbox
    {
        return $this->outbox;
    }

    public function relay(): DatabaseRelay
    {
        return $this->relay;
    }

    /**
     * Register the post_system hook. Call from app/Config/Events.php:
     *   \TransactionalOutbox\Adapter\CodeIgniter4\Outbox::boot();
     */
    public static function boot(?OutboxConfig $settings = null): void
    {
        $settings ??= self::settings();

        if (! $settings->autoRelay) {
            return;
        }

        \CodeIgniter\Events\Events::on('post_system', static function () use ($settings): void {
            try {
                self::make($settings)->relay()->run();
            } catch (\Throwable $e) {
                log_message('error', 'transactional-outbox: ' . $e->getMessage());
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    public static function toArray(OutboxConfig $settings): array
    {
        return [
            'table' => $settings->table,
            'batch_table' => $settings->batchTable,
            'dead_letter_table' => $settings->deadLetterTable,
            'max_attempts' => $settings->maxAttempts,
            'base_delay_seconds' => $settings->baseDelaySeconds,
            'max_delay_seconds' => $settings->maxDelaySeconds,
            'batch_size' => $settings->batchSize,
            'claim_timeout_seconds' => $settings->claimTimeoutSeconds,
            'auto_install' => $settings->autoInstall,
        ];
    }

    private static function settings(): OutboxConfig
    {
        if (class_exists(\Config\Outbox::class)) {
            $config = config(\Config\Outbox::class);

            if ($config instanceof OutboxConfig) {
                return $config;
            }
        }

        return new OutboxConfig();
    }

    /**
     * Opens the PDO connection the outbox uses.
     *
     * CodeIgniter talks to the database through its own drivers (MySQLi,
     * Postgre, SQLite3), which do not expose a PDO handle. The outbox needs
     * PDO, so the connection is built from the CodeIgniter database config
     * instead. Both sides then point at the same database.
     */
    private static function pdo(OutboxConfig $settings): PDO
    {
        if ($settings->pdo instanceof PDO) {
            return $settings->pdo;
        }

        $group = $settings->connection !== '' ? $settings->connection : 'default';
        $config = self::databaseConfig($group);

        $pdo = new PDO(
            self::dsn($config),
            (string) ($config['username'] ?? ''),
            (string) ($config['password'] ?? ''),
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ],
        );

        return $pdo;
    }

    /**
     * Reads a connection group from the CodeIgniter database config.
     *
     * @return array<string, mixed>
     */
    public static function databaseConfig(string $group): array
    {
        $config = self::rawDatabaseConfig();

        $connection = $config[$group] ?? null;

        if (! is_array($connection)) {
            throw new \RuntimeException(sprintf(
                'The database connection "%s" is not configured.',
                $group,
            ));
        }

        return $connection;
    }

    /**
     * The raw database config, whichever way this application exposes it.
     *
     * @return array<string, mixed>
     */
    private static function rawDatabaseConfig(): array
    {
        if (function_exists('config')) {
            $config = config('Database');

            if (is_array($config)) {
                return $config;
            }
        }

        if (! class_exists(\Config\Database::class)) {
            return [];
        }

        $config = config(\Config\Database::class);

        if (! is_object($config)) {
            return [];
        }

        $groups = [];

        foreach (get_object_vars($config) as $name => $value) {
            if (is_array($value)) {
                $groups[$name] = $value;
            }
        }

        return $groups;
    }

    /**
     * Builds a PDO DSN from a CodeIgniter database config.
     *
     * @param array<string, mixed> $config
     */
    public static function dsn(array $config): string
    {
        $driver = (string) ($config['DBDriver'] ?? 'MySQLi');
        $database = (string) ($config['database'] ?? '');

        // A ready-made DSN wins over anything the adapter could build.
        if (! empty($config['DSN'])) {
            return (string) $config['DSN'];
        }

        return match ($driver) {
            'SQLite3' => 'sqlite:' . $database,
            'Postgre' => sprintf(
                'pgsql:host=%s;port=%d;dbname=%s',
                (string) ($config['hostname'] ?? 'localhost'),
                (int) ($config['port'] ?? 5432),
                $database,
            ),
            default => sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                (string) ($config['hostname'] ?? 'localhost'),
                (int) ($config['port'] ?? 3306),
                $database,
                (string) ($config['charset'] ?? 'utf8mb4'),
            ),
        };
    }

    private static function publisher(OutboxConfig $settings): Publisher
    {
        $publisher = $settings->publisher;

        if ($publisher instanceof Publisher) {
            return $publisher;
        }

        if (is_string($publisher) && $publisher !== '') {
            $resolved = service($publisher);

            if (! $resolved instanceof Publisher) {
                throw new \InvalidArgumentException(sprintf(
                    '%s must implement %s.',
                    $publisher,
                    Publisher::class,
                ));
            }

            return $resolved;
        }

        if (is_callable($publisher)) {
            $resolved = $publisher();

            if (! $resolved instanceof Publisher) {
                throw new \InvalidArgumentException('The outbox publisher callable must return a Publisher.');
            }

            return $resolved;
        }

        return new LogPublisher();
    }
}
