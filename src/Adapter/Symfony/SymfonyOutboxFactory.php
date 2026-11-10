<?php

declare(strict_types=1);

namespace TransactionalOutbox\Adapter\Symfony;

use PDO;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use TransactionalOutbox\Core\DatabaseRelay;
use TransactionalOutbox\Core\Outbox;
use TransactionalOutbox\Core\OutboxFactory;
use TransactionalOutbox\Core\Publisher;
use TransactionalOutbox\Core\Publisher\Psr14Publisher;
use TransactionalOutbox\Core\Storage\PdoOutboxStore;

/**
 * Resolves the Symfony services the outbox needs.
 *
 * The extension registers this factory, so the container decides which PDO
 * connection and which dispatcher are used. Both are looked up lazily, which
 * keeps the bundle free of a hard Doctrine or Messenger dependency.
 */
final class SymfonyOutboxFactory
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly array $config,
    ) {
    }

    public function createOutbox(): Outbox
    {
        return OutboxFactory::fromConnection($this->pdo(), $this->coreConfig());
    }

    public function createStore(): PdoOutboxStore
    {
        return OutboxFactory::storeFrom($this->pdo(), $this->coreConfig());
    }

    public function createPublisher(): Publisher
    {
        $dispatcher = $this->container->get((string) ($this->config['dispatcher'] ?? 'event_dispatcher'));

        if (! $dispatcher instanceof EventDispatcherInterface && ! method_exists($dispatcher, 'dispatch')) {
            throw new \InvalidArgumentException(sprintf(
                'The dispatcher service "%s" must implement %s.',
                (string) ($this->config['dispatcher'] ?? 'event_dispatcher'),
                EventDispatcherInterface::class,
            ));
        }

        return new Psr14Publisher(static fn (object $event): mixed => $dispatcher->dispatch($event));
    }

    public function createRelay(): DatabaseRelay
    {
        return OutboxFactory::relayFrom($this->createStore(), $this->createPublisher(), $this->coreConfig());
    }

    /**
     * The core settings, without the Symfony service references.
     *
     * @return array<string, mixed>
     */
    private function coreConfig(): array
    {
        return array_diff_key($this->config, array_flip(['pdo', 'dispatcher', 'connection']));
    }

    private function pdo(): PDO
    {
        $id = (string) ($this->config['pdo'] ?? 'doctrine.dbal.default_connection');
        $connection = $this->container->get($id);

        // A Doctrine connection exposes its PDO handle; a raw PDO service is
        // returned as is.
        if ($connection instanceof PDO) {
            return $connection;
        }

        if (method_exists($connection, 'getNativeConnection')) {
            $native = $connection->getNativeConnection();

            if ($native instanceof PDO) {
                return $native;
            }
        }

        if (method_exists($connection, 'getPdo')) {
            $native = $connection->getPdo();

            if ($native instanceof PDO) {
                return $native;
            }
        }

        throw new \InvalidArgumentException(sprintf(
            'The service "%s" does not expose a PDO connection.',
            $id,
        ));
    }
}
