<?php

/**
 * Locomotive
 *
 * Copyright (c) 2015 Joshua Smith
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @package     Locomotive
 * @subpackage  Locomotive\Configuration
 */

namespace Locomotive\Configuration;

use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;

class LocomoteConfiguration implements ConfigurationInterface
{
    /**
     * Defines configuration options for the `locomote` default command.
     *
     * @return TreeBuilder
     *
     * @throws \RuntimeException
     **/
    public function getConfigTreeBuilder()
    {
        $treeBuilder = new TreeBuilder();
        $rootNode = $treeBuilder->root('locomote');

        // config definitions
        $rootNode
            ->normalizeKeys(false)
            ->children()
                ->scalarNode('lftp-path')->end()
                ->scalarNode('public-keyfile')->end()
                ->scalarNode('private-keyfile')->end()
                ->scalarNode('username')
                    ->isRequired()
                    ->cannotBeEmpty()
                ->end()
                ->scalarNode('password')->end()
                ->scalarNode('port')
                    ->defaultValue(22)
                ->end()
                ->scalarNode('working-dir')->end()
                ->scalarNode('speed-limit')
                    ->defaultValue(0)
                ->end()
                ->scalarNode('connection-limit')
                    ->defaultValue(25)
                ->end()
                ->scalarNode('transfer-limit')
                    ->defaultValue(3)
                ->end()
                ->scalarNode('max-retries')
                    ->defaultValue(5)
                ->end()
                ->scalarNode('newer-than')->end()
                ->booleanNode('zip-sources')
                    ->isRequired()
                    ->cannotBeEmpty()
                    ->defaultFalse()
                ->end()
                ->arrayNode('remove-sources')
                    ->isRequired()
                    ->cannotBeEmpty()
                    ->children()
                        ->booleanNode('remove')
                            ->isRequired()
                            ->cannotBeEmpty()
                            ->defaultFalse()
                            ->validate()
                                ->ifNull()
                                ->then(function() { return false; })
                            ->end()
                        ->end()
                        ->arrayNode('exclude')
                            ->performNoDeepMerging()
                            ->prototype('scalar')->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('speed-schedule')
                    ->useAttributeAsKey(true)
                    ->normalizeKeys(false)
                    ->performNoDeepMerging()
                    ->prototype('scalar')->end()
                ->end()
                ->arrayNode('source-target-map')
                    ->useAttributeAsKey(true)
                    ->normalizeKeys(false)
                    ->performNoDeepMerging()
                    ->prototype('scalar')->end()
                ->end()
                ->arrayNode('post-processors')
                    ->performNoDeepMerging()
                    ->prototype('scalar')->end()
                ->end()

                ->append($this->addNotificationsNode())

            ->end();

        return $treeBuilder;
    }

    public function addNotificationsNode()
    {
        $treeBuilder = new TreeBuilder();
        $node = $treeBuilder->root('notifications');

        $node
            ->performNoDeepMerging()
            ->children()

                ->arrayNode('prowl')
                    ->treatFalseLike(array('enabled' => false))
                    ->treatTrueLike(array('enabled' => true))
                    ->treatNullLike(array('enabled' => false))
                    ->normalizeKeys(false)
                    ->children()

                        ->booleanNode('enable')
                            ->defaultFalse()
                        ->end()

                        ->scalarNode('api-key')
                            ->isRequired()
                        ->end()

                        ->arrayNode('events')
                            ->performNoDeepMerging()
                            ->prototype('scalar')
                            ->validate()
                            ->ifNotInArray(array('transferStarted', 'itemMoved'))
                                ->thenInvalid('%s')
                            ->end()
                        ->end()

                    ->end()
                ->end()

            ->end();

        return $node;
    }
}