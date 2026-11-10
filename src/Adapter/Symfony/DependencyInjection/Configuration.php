<?php

declare(strict_types=1);

namespace TransactionalOutbox\Adapter\Symfony\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('transactional_outbox');

        $treeBuilder->getRootNode()
            ->children()
                ->scalarNode('pdo')
                    ->info('Service id of a service that returns a PDO instance.')
                    ->defaultValue('doctrine.dbal.default_connection')
                    ->cannotBeEmpty()
                ->end()
                ->scalarNode('dispatcher')
                    ->info('Service id of a PSR-14 event dispatcher.')
                    ->defaultValue('event_dispatcher')
                    ->cannotBeEmpty()
                ->end()
                ->scalarNode('connection')
                    ->info('Optional database connection name passed to the Doctrine connection.')
                    ->defaultNull()
                ->end()
                ->scalarNode('table')->defaultValue('outbox_messages')->cannotBeEmpty()->end()
                ->scalarNode('batch_table')->defaultValue('outbox_batches')->cannotBeEmpty()->end()
                ->scalarNode('dead_letter_table')->defaultValue('outbox_dead_letters')->cannotBeEmpty()->end()
                ->integerNode('max_attempts')->defaultValue(5)->min(1)->end()
                ->integerNode('base_delay_seconds')->defaultValue(10)->min(0)->end()
                ->integerNode('max_delay_seconds')->defaultValue(3600)->min(0)->end()
                ->integerNode('batch_size')->defaultValue(100)->min(1)->end()
                ->integerNode('claim_timeout_seconds')->defaultValue(300)->min(1)->end()
                ->booleanNode('auto_install')->defaultTrue()->end()
            ->end();

        return $treeBuilder;
    }
}
