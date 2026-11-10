<?php

declare(strict_types=1);

namespace TransactionalOutbox\Tests\Integration;

use TransactionalOutbox\Core\OutboxConfig;
use TransactionalOutbox\Core\OutboxFactory;
use TransactionalOutbox\Tests\TestCase;

/**
 * The shipped framework config files must stay compatible with the core
 * factory, otherwise every adapter breaks at boot.
 */
final class ConfigShapeTest extends TestCase
{
    public function testLaravelConfigBuildsTheDefaultStore(): void
    {
        $config = require dirname(__DIR__, 2) . '/src/Adapter/Laravel/config/transactional-outbox.php';

        self::assertIsArray($config);

        $outboxConfig = OutboxConfig::fromArray($config);

        self::assertSame('outbox_messages', $outboxConfig->table);
        self::assertSame(5, $outboxConfig->maxAttempts);
    }

    public function testCodeIgniterConfigBuildsTheDefaultStore(): void
    {
        $settings = new \TransactionalOutbox\Adapter\CodeIgniter4\Config\Outbox();

        $config = \TransactionalOutbox\Adapter\CodeIgniter4\Outbox::toArray($settings);

        $outboxConfig = OutboxConfig::fromArray($config);

        self::assertSame('outbox_messages', $outboxConfig->table);
        self::assertSame(100, $outboxConfig->batchSize);
    }

    public function testCakePhpConfigBuildsTheDefaultStore(): void
    {
        $settings = new \TransactionalOutbox\Adapter\CakePHP\Config\Outbox();

        $config = \TransactionalOutbox\Adapter\CakePHP\Outbox::toArray($settings);

        $outboxConfig = OutboxConfig::fromArray($config);

        self::assertSame('outbox_messages', $outboxConfig->table);
        self::assertTrue($outboxConfig->autoInstall);
    }

    public function testYiiConfigBuildsTheDefaultStore(): void
    {
        $settings = new \TransactionalOutbox\Adapter\Yii\Config\Outbox();

        $config = \TransactionalOutbox\Adapter\Yii\Outbox::toArray($settings);

        $outboxConfig = OutboxConfig::fromArray($config);

        self::assertSame('outbox_messages', $outboxConfig->table);
        self::assertSame(300, $outboxConfig->claimTimeoutSeconds);
    }

    public function testEveryAdapterProducesTheSameConfigShape(): void
    {
        $shapes = [];

        $shapes['laravel'] = OutboxConfig::fromArray(
            require dirname(__DIR__, 2) . '/src/Adapter/Laravel/config/transactional-outbox.php',
        )->toArray();

        $shapes['codeigniter4'] = OutboxConfig::fromArray(
            \TransactionalOutbox\Adapter\CodeIgniter4\Outbox::toArray(
                new \TransactionalOutbox\Adapter\CodeIgniter4\Config\Outbox(),
            ),
        )->toArray();

        $shapes['symfony'] = OutboxConfig::fromArray([
            'table' => 'outbox_messages',
            'batch_table' => 'outbox_batches',
            'dead_letter_table' => 'outbox_dead_letters',
            'max_attempts' => 5,
            'base_delay_seconds' => 10,
            'max_delay_seconds' => 3600,
            'batch_size' => 100,
            'claim_timeout_seconds' => 300,
            'auto_install' => true,
        ])->toArray();

        $shapes['yii'] = OutboxConfig::fromArray(
            \TransactionalOutbox\Adapter\Yii\Outbox::toArray(
                new \TransactionalOutbox\Adapter\Yii\Config\Outbox(),
            ),
        )->toArray();

        $shapes['cakephp'] = OutboxConfig::fromArray(
            \TransactionalOutbox\Adapter\CakePHP\Outbox::toArray(
                new \TransactionalOutbox\Adapter\CakePHP\Config\Outbox(),
            ),
        )->toArray();

        $reference = $shapes['laravel'];

        foreach ($shapes as $framework => $shape) {
            self::assertSame($reference, $shape, sprintf('%s config differs from Laravel.', $framework));
        }
    }
}
