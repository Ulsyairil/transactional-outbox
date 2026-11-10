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

    private static function pdo(OutboxConfig $settings): PDO
    {
        if ($settings->pdo instanceof PDO) {
            return $settings->pdo;
        }

        $connection = ConnectionManager::get($settings->connection !== '' ? $settings->connection : 'default');

        if (! method_exists($connection, 'getDriver')) {
            throw new \RuntimeException('The configured CakePHP connection does not expose a driver.');
        }

        $driver = $connection->getDriver();

        if (! method_exists($driver, 'getConnection')) {
            throw new \RuntimeException('The configured CakePHP connection does not expose a PDO connection.');
        }

        $pdo = $driver->getConnection();

        if (! $pdo instanceof PDO) {
            throw new \RuntimeException('The configured CakePHP connection does not expose a PDO connection.');
        }

        return $pdo;
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
