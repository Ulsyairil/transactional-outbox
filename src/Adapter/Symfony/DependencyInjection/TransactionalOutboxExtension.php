<?php

declare(strict_types=1);

namespace TransactionalOutbox\Adapter\Symfony\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;
use TransactionalOutbox\Adapter\Symfony\SymfonyOutboxFactory;
use TransactionalOutbox\Core\DatabaseRelay;
use TransactionalOutbox\Core\Outbox;
use TransactionalOutbox\Core\Publisher;

final class TransactionalOutboxExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $config = $this->processConfiguration(new Configuration(), $configs);

        $factory = new Definition(SymfonyOutboxFactory::class, [
            new Reference('service_container'),
            $config,
        ]);
        $factory->setPublic(false);

        $container->setDefinition(SymfonyOutboxFactory::class, $factory);

        $container->setDefinition(Outbox::class, (new Definition(Outbox::class))
            ->setFactory([new Reference(SymfonyOutboxFactory::class), 'createOutbox'])
            ->setPublic(true));

        $container->setDefinition(Publisher::class, (new Definition(Publisher::class))
            ->setFactory([new Reference(SymfonyOutboxFactory::class), 'createPublisher'])
            ->setPublic(true));

        $container->setDefinition(DatabaseRelay::class, (new Definition(DatabaseRelay::class))
            ->setFactory([new Reference(SymfonyOutboxFactory::class), 'createRelay'])
            ->setPublic(true));

        $container->setAlias('transactional_outbox', Outbox::class)->setPublic(true);
        $container->setAlias('transactional_outbox.relay', DatabaseRelay::class)->setPublic(true);
    }

    public function getAlias(): string
    {
        return 'transactional_outbox';
    }
}
