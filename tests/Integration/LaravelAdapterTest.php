<?php

declare(strict_types=1);

namespace TransactionalOutbox\Tests\Integration;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Events\Dispatcher;
use TransactionalOutbox\Adapter\Laravel\Facades\OutboxFacade;
use TransactionalOutbox\Adapter\Laravel\TransactionalOutboxServiceProvider;
use TransactionalOutbox\Core\DatabaseRelay;
use TransactionalOutbox\Core\Outbox;
use TransactionalOutbox\Core\Publisher;
use TransactionalOutbox\Core\Publisher\OutboxEvent;
use TransactionalOutbox\Tests\TestCase;

/**
 * The Laravel adapter must wire the container the way a real application
 * expects: one Outbox, one Publisher, one Relay.
 */
final class LaravelAdapterTest extends TestCase
{
    private ?Container $container = null;

    protected function tearDown(): void
    {
        if ($this->container !== null) {
            $this->container->flush();
            $this->container = null;
        }

        parent::tearDown();
    }

    private function boot(): Container
    {
        $container = new Container();
        Container::setInstance($container);

        $capsule = new Capsule($container);
        $capsule->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ], 'default');
        $capsule->setEventDispatcher(new Dispatcher($container));
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        $container->instance('config', new Repository([
            'database' => [
                'default' => 'default',
                'connections' => [
                    'default' => [
                        'driver' => 'sqlite',
                        'database' => ':memory:',
                        'prefix' => '',
                    ],
                ],
            ],
            'transactional-outbox' => require dirname(__DIR__, 2) . '/src/Adapter/Laravel/config/transactional-outbox.php',
        ]));
        $container->instance('db', $capsule->getDatabaseManager());
        $container->instance('events', $capsule->getEventDispatcher());

        $provider = new TransactionalOutboxServiceProvider($container);
        $provider->register();

        return $this->container = $container;
    }

    public function testRegistersTheOutboxSingleton(): void
    {
        $container = $this->boot();

        $outbox = $container->make(Outbox::class);

        self::assertInstanceOf(Outbox::class, $outbox);
        self::assertSame($outbox, $container->make(Outbox::class));
        self::assertSame($outbox, $container->make('transactional-outbox'));
    }

    public function testInstallsTheSchemaOnResolution(): void
    {
        $container = $this->boot();

        $container->make(Outbox::class);

        $pdo = $container->make('db')->connection()->getPdo();
        $statement = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'outbox_messages'");

        self::assertSame('outbox_messages', $statement->fetchColumn());
    }

    public function testStoresAndPublishesAMessage(): void
    {
        $container = $this->boot();

        $received = [];
        $container->make('events')->listen(
            OutboxEvent::class,
            static function (OutboxEvent $event) use (&$received): void {
                $received[] = $event->eventType;
            },
        );

        $outbox = $container->make(Outbox::class);
        $outbox->publish('order.created', ['order_id' => 1]);

        $result = $container->make(DatabaseRelay::class)->run();

        self::assertSame(1, $result->published);
        self::assertSame(['order.created'], $received);
        self::assertSame(0, $outbox->pendingCount());
    }

    public function testPublisherDefaultsToTheEventBus(): void
    {
        $container = $this->boot();

        self::assertInstanceOf(Publisher::class, $container->make(Publisher::class));
    }

    public function testFacadeResolvesTheOutbox(): void
    {
        $container = $this->boot();

        OutboxFacade::clearResolvedInstances();
        OutboxFacade::setFacadeApplication($container);

        self::assertInstanceOf(Outbox::class, OutboxFacade::getFacadeRoot());
    }
}
