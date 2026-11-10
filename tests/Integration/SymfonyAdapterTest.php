<?php

declare(strict_types=1);

namespace TransactionalOutbox\Tests\Integration;

use PDO;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use TransactionalOutbox\Adapter\Symfony\DependencyInjection\Configuration;
use TransactionalOutbox\Adapter\Symfony\DependencyInjection\TransactionalOutboxExtension;
use TransactionalOutbox\Core\DatabaseRelay;
use TransactionalOutbox\Core\Outbox;
use TransactionalOutbox\Core\Publisher;
use TransactionalOutbox\Core\Publisher\OutboxEvent;
use TransactionalOutbox\Tests\TestCase;

/**
 * The Symfony adapter must resolve the PDO connection and the dispatcher
 * through the container, so the application decides which ones are used.
 */
final class SymfonyAdapterTest extends TestCase
{
    private function container(PDO $pdo, FakeDispatcher $dispatcher): ContainerBuilder
    {
        $container = new ContainerBuilder();

        $container->setDefinition('outbox.pdo', (new Definition(PDO::class))
            ->setFactory([self::class, 'makePdo'])
            ->setArguments([$pdo])
            ->setPublic(true));

        $container->set('event_dispatcher', $dispatcher);

        $extension = new TransactionalOutboxExtension();
        $extension->load([[
            'pdo' => 'outbox.pdo',
            'dispatcher' => 'event_dispatcher',
        ]], $container);

        return $container;
    }

    public static function makePdo(PDO $pdo): PDO
    {
        return $pdo;
    }

    public function testConfigurationDefaults(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[]]);

        self::assertSame('outbox_messages', $config['table']);
        self::assertSame(5, $config['max_attempts']);
        self::assertTrue($config['auto_install']);
    }

    public function testRegistersTheOutboxService(): void
    {
        $container = $this->container($this->pdo(), new FakeDispatcher());

        $outbox = $container->get(Outbox::class);

        self::assertInstanceOf(Outbox::class, $outbox);
        self::assertSame($outbox, $container->get('transactional_outbox'));
    }

    public function testInstallsTheSchemaOnResolution(): void
    {
        $pdo = $this->pdo();
        $container = $this->container($pdo, new FakeDispatcher());

        $container->get(Outbox::class);

        $statement = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'outbox_messages'");

        self::assertSame('outbox_messages', $statement->fetchColumn());
    }

    public function testRelayPublishesThroughTheDispatcher(): void
    {
        $dispatcher = new FakeDispatcher();
        $container = $this->container($this->pdo(), $dispatcher);

        $outbox = $container->get(Outbox::class);
        $outbox->publish('order.created', ['order_id' => 1]);

        $result = $container->get(DatabaseRelay::class)->run();

        self::assertSame(1, $result->published);
        self::assertCount(1, $dispatcher->events);
        self::assertSame('order.created', $dispatcher->events[0]->eventType);
    }

    public function testPublisherServiceIsRegistered(): void
    {
        $container = $this->container($this->pdo(), new FakeDispatcher());

        self::assertInstanceOf(Publisher::class, $container->get(Publisher::class));
    }
}

/**
 * Dispatcher that records the events it receives.
 */
final class FakeDispatcher
{
    /** @var list<OutboxEvent> */
    public array $events = [];

    public function dispatch(object $event): object
    {
        if ($event instanceof OutboxEvent) {
            $this->events[] = $event;
        }

        return $event;
    }
}
