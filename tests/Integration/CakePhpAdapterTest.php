<?php

declare(strict_types=1);

namespace TransactionalOutbox\Tests\Integration;

use TransactionalOutbox\Adapter\CakePHP\Config\Outbox as CakeOutboxConfig;
use TransactionalOutbox\Adapter\CakePHP\Outbox as CakeOutbox;
use TransactionalOutbox\Tests\TestCase;

/**
 * The CakePHP adapter must build a working outbox from a plain PDO
 * connection, without needing a bootstrapped application.
 */
final class CakePhpAdapterTest extends TestCase
{
    public function testBuildsAnOutboxFromAPdoConnection(): void
    {
        $pdo = $this->pdo();

        $settings = new CakeOutboxConfig();
        $settings->pdo = $pdo;

        $outbox = CakeOutbox::make($settings)->outbox();

        $outbox->publish('order.created', ['order_id' => 1]);

        self::assertSame(1, $outbox->pendingCount());

        $statement = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'outbox_messages'");
        self::assertSame('outbox_messages', $statement->fetchColumn());
    }

    public function testConfigArrayMatchesTheCoreDefaults(): void
    {
        $config = CakeOutbox::toArray(new CakeOutboxConfig());

        self::assertSame('outbox_messages', $config['table']);
        self::assertSame(5, $config['max_attempts']);
        self::assertSame(300, $config['claim_timeout_seconds']);
        self::assertTrue($config['auto_install']);
    }
}
