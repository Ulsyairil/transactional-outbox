<?php

declare(strict_types=1);

namespace TransactionalOutbox\Tests\Integration;

use Cake\Core\Configure;
use Cake\Datasource\ConnectionManager;
use PDO;
use TransactionalOutbox\Adapter\CakePHP\Config\Outbox as CakeOutboxConfig;
use TransactionalOutbox\Adapter\CakePHP\Outbox as CakeOutbox;
use TransactionalOutbox\Adapter\CakePHP\OutboxPlugin;
use TransactionalOutbox\Core\Outbox;
use TransactionalOutbox\Tests\TestCase;

/**
 * The CakePHP adapter must resolve the PDO connection from the datasource
 * config, not only from an injected PDO.
 */
final class CakePhpAdapterTest extends TestCase
{
    protected function tearDown(): void
    {
        ConnectionManager::drop('outbox_test');
        Configure::delete('TransactionalOutbox');

        parent::tearDown();
    }

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

    public function testTheDatasourceConfigIsTurnedIntoAPdoConnection(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'outbox-cake-');

        ConnectionManager::setConfig('outbox_test', [
            'className' => 'Cake\\Database\\Connection',
            'driver' => 'Cake\\Database\\Driver\\Sqlite',
            'database' => $file,
        ]);

        $settings = new CakeOutboxConfig();
        $settings->connection = 'outbox_test';

        $outbox = CakeOutbox::make($settings)->outbox();

        $outbox->publish('order.created', ['order_id' => 1]);

        self::assertSame(1, $outbox->pendingCount());

        $pdo = new PDO('sqlite:' . $file);
        $statement = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'outbox_messages'");

        self::assertSame('outbox_messages', $statement->fetchColumn());

        unlink($file);
    }

    public function testThePluginNormalisesTheConfigArray(): void
    {
        Configure::write(CakeOutbox::CONFIG_KEY, [
            'connection' => 'outbox_test',
            'maxAttempts' => 9,
            'batchSize' => 42,
            'autoInstall' => false,
        ]);

        $plugin = new OutboxPlugin();
        $plugin->bootstrap($this->createMock(\Cake\Core\PluginApplicationInterface::class));

        $settings = Configure::read(CakeOutbox::CONFIG_KEY);

        self::assertInstanceOf(CakeOutboxConfig::class, $settings);
        self::assertSame('outbox_test', $settings->connection);
        self::assertSame(9, $settings->maxAttempts);
        self::assertSame(42, $settings->batchSize);
        self::assertFalse($settings->autoInstall);
    }

    public function testThePluginRegistersTheRelayCommand(): void
    {
        $plugin = new OutboxPlugin();

        $commands = $plugin->console(new \Cake\Console\CommandCollection());

        self::assertTrue($commands->has('outbox:relay'));
    }

    public function testTheOutboxIsTheSameInstanceAcrossCalls(): void
    {
        $settings = new CakeOutboxConfig();
        $settings->pdo = $this->pdo();

        $first = CakeOutbox::make($settings);
        $second = CakeOutbox::make($settings);

        self::assertInstanceOf(Outbox::class, $first->outbox());
        self::assertInstanceOf(Outbox::class, $second->outbox());
    }
}
