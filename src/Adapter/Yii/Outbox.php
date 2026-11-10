<?php

declare(strict_types=1);

namespace TransactionalOutbox\Adapter\Yii;

use PDO;
use TransactionalOutbox\Adapter\Yii\Config\Outbox as OutboxConfig;
use TransactionalOutbox\Core\DatabaseRelay;
use TransactionalOutbox\Core\Outbox as CoreOutbox;
use TransactionalOutbox\Core\OutboxFactory;
use TransactionalOutbox\Core\Publisher;
use TransactionalOutbox\Core\Publisher\LogPublisher;

/**
 * Convenience factory for Yii 2.
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

    /**
     * Reads the settings.
     *
     * Three ways to configure it, in order:
     *  1. a component named "outbox" that returns an OutboxConfig
     *  2. `Yii::$app->params['outbox']` as an array of settings
     *  3. the defaults
     */
    private static function settings(): OutboxConfig
    {
        if (\Yii::$app !== null) {
            if (\Yii::$app->has('outbox')) {
                $component = \Yii::$app->get('outbox');

                if ($component instanceof OutboxConfig) {
                    return $component;
                }
            }

            $params = \Yii::$app->params;

            if (isset($params['outbox']) && is_array($params['outbox'])) {
                return self::fromArray($params['outbox']);
            }
        }

        return new OutboxConfig();
    }

    /**
     * @param array<string, mixed> $values
     */
    private static function fromArray(array $values): OutboxConfig
    {
        $settings = new OutboxConfig();

        foreach (get_object_vars($settings) as $property => $default) {
            if (! array_key_exists($property, $values)) {
                continue;
            }

            $value = $values[$property];

            $settings->{$property} = match (true) {
                is_bool($default) => (bool) $value,
                is_int($default) => (int) $value,
                is_string($default) => (string) $value,
                default => $value,
            };
        }

        return $settings;
    }

    private static function pdo(OutboxConfig $settings): PDO
    {
        if ($settings->pdo instanceof PDO) {
            return $settings->pdo;
        }

        $dsn = $settings->dsn !== '' ? $settings->dsn : \Yii::$app->db->dsn;
        $pdo = new PDO($dsn, $settings->username, $settings->password);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        return $pdo;
    }

    private static function publisher(OutboxConfig $settings): Publisher
    {
        $publisher = $settings->publisher;

        if ($publisher instanceof Publisher) {
            return $publisher;
        }

        if (is_string($publisher) && $publisher !== '') {
            $resolved = \Yii::$app->get($publisher);

            if (! $resolved instanceof Publisher) {
                throw new \InvalidArgumentException(sprintf(
                    '%s must implement %s.',
                    $publisher,
                    Publisher::class,
                ));
            }

            return $resolved;
        }

        return new LogPublisher();
    }
}
