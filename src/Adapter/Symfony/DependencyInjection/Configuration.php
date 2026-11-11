<?php

declare(strict_types=1);

namespace TransactionalOutbox\Adapter\Symfony\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('transactional_outbox');

        $rootNode = $treeBuilder->getRootNode();

        // Symfony 6.4 types getRootNode() as NodeDefinition|ArrayNodeDefinition,
        // 7.x as NodeDefinition. The runtime value is always an array node.
        if (! $rootNode instanceof ArrayNodeDefinition) {
            throw new \LogicException('The outbox configuration root must be an array node.');
        }

        // The builder is kept in a variable instead of chaining through
        // end(), because the return type of end() differs between Symfony 6.4
        // and 7.x.
        $children = $rootNode->children();

        $children->scalarNode('pdo')
            ->info('Service id of a service that returns a PDO instance.')
            ->defaultValue('doctrine.dbal.default_connection')
            ->cannotBeEmpty();

        $children->scalarNode('dispatcher')
            ->info('Service id of a PSR-14 event dispatcher.')
            ->defaultValue('event_dispatcher')
            ->cannotBeEmpty();

        $children->scalarNode('connection')
            ->info('Optional database connection name passed to the Doctrine connection.')
            ->defaultNull();

        $children->scalarNode('table')->defaultValue('outbox_messages')->cannotBeEmpty();
        $children->scalarNode('batch_table')->defaultValue('outbox_batches')->cannotBeEmpty();
        $children->scalarNode('dead_letter_table')->defaultValue('outbox_dead_letters')->cannotBeEmpty();
        $children->integerNode('max_attempts')->defaultValue(5)->min(1);
        $children->integerNode('base_delay_seconds')->defaultValue(10)->min(0);
        $children->integerNode('max_delay_seconds')->defaultValue(3600)->min(0);
        $children->integerNode('batch_size')->defaultValue(100)->min(1);
        $children->integerNode('claim_timeout_seconds')->defaultValue(300)->min(1);
        $children->booleanNode('auto_install')->defaultTrue();

        return $treeBuilder;
    }
}
