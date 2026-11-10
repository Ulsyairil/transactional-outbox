<?php

declare(strict_types=1);

namespace TransactionalOutbox\Adapter\CakePHP;

use Cake\Core\Configure;
use Cake\Datasource\ConnectionManager;
use PDO;
use TransactionalOutbox\Adapter\CakePHP\Config\Outbox as OutboxConfig;
use TransactionalOutbox\Core\DatabaseRelay;
use TransactionalOutbox\Core\Outbox as CoreOutbox;
use TransactionalOutbox\Core\OutboxFactory;
use TransactionalOutbox\Core\Publisher;
use TransactionalOutbox\Core\Publisher\LogPublisher;

/**
 * Convenience factory for CakePHP.
 */
final class Outbox
{
    public const CONFIG_KEY = 'TransactionalOutbox';

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
        $config = Configure::read(self::CONFIG_KEY);

        if ($config instanceof OutboxConfig) {
            return $config;
        }

        if (! is_array($config)) {
            return new OutboxConfig();
        }

        return self::settingsFromArray($config);
    }

    /**
     * Maps a config array onto the settings object.
     *
     * @param array<string, mixed> $config
     */
    public static function settingsFromArray(array $config): OutboxConfig
    {
        $settings = new OutboxConfig();

        foreach (get_object_vars($settings) as $property => $default) {
            if (! array_key_exists($property, $config)) {
                continue;
            }

            $value = $config[$property];

            if (is_array($default)) {
                $settings->{$property} = $value;

                continue;
            }

            $settings->{$property} = is_scalar($value) || $value === null
                ? match (true) {
                    is_bool($default) => (bool) $value,
                    is_int($default) => (int) $value,
                    is_string($default) => (string) $value,
                    default => $value,
                }
                : $value;
        }

        return $settings;
    }

    /**
     * Opens the PDO connection the outbox uses.
     *
     * CakePHP keeps its PDO handle private inside the driver, so the
     * connection is built from the datasource config instead. Both sides then
     * point at the same database.
     */
    private static function pdo(OutboxConfig $settings): PDO
    {
        if ($settings->pdo instanceof PDO) {
            return $settings->pdo;
        }

        $name = $settings->connection !== '' ? $settings->connection : 'default';
        $connection = ConnectionManager::get($name);
        $config = $connection->config();

        if ($config === []) {
            throw new \RuntimeException(sprintf(
                'The CakePHP datasource "%s" is not configured.',
                $name,
            ));
        }

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
     * Builds a PDO DSN from a CakePHP datasource config.
     *
     * @param array<string, mixed> $config
     */
    private static function dsn(array $config): string
    {
        $driver = (string) ($config['driver'] ?? 'Cake\\Database\\Driver\\Mysql');
        $database = (string) ($config['database'] ?? '');

        // A ready-made DSN wins over anything the adapter could build.
        if (! empty($config['dsn'])) {
            return (string) $config['dsn'];
        }

        if (str_contains($driver, 'Sqlite')) {
            return 'sqlite:' . $database;
        }

        if (str_contains($driver, 'Postgres')) {
            return sprintf(
                'pgsql:host=%s;port=%d;dbname=%s',
                (string) ($config['host'] ?? 'localhost'),
                (int) ($config['port'] ?? 5432),
                $database,
            );
        }

        return sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            (string) ($config['host'] ?? 'localhost'),
            (int) ($config['port'] ?? 3306),
            $database,
            (string) ($config['encoding'] ?? 'utf8mb4'),
        );
    }

    private static function publisher(OutboxConfig $settings): Publisher
    {
        $publisher = $settings->publisher;

        if ($publisher instanceof Publisher) {
            return $publisher;
        }

        return new LogPublisher();
    }
}
