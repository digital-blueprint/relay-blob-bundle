<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('dbp_relay_blob');

        $treeBuilder
            ->getRootNode()
                ->children()
                    ->scalarNode('database_url')
                        ->isRequired()
                        ->cannotBeEmpty()
                        ->defaultValue('%env(resolve:DATABASE_URL)%')
                    ->end()
                    ->arrayNode('buckets')
                        ->isRequired()
                        ->cannotBeEmpty()
                        ->arrayPrototype()
                            ->children()
                                ->scalarNode('service')
                                    ->isRequired()
                                    ->cannotBeEmpty()
                                ->end()
                                ->scalarNode('bucket_id')
                                    ->isRequired()
                                    ->cannotBeEmpty()
                                ->end()
                                ->scalarNode('bucket_name')
                                    ->isRequired()
                                    ->cannotBeEmpty()
                                ->end()
                                ->scalarNode('public_key')
                                    ->isRequired()
                                    ->cannotBeEmpty()
                                ->end()
                                ->scalarNode('path')
                                    ->defaultValue('')
                                ->end()
                                ->scalarNode('quota')
                                    ->isRequired()
                                    ->cannotBeEmpty()
                                ->end()
                                ->scalarNode('max_retention_duration')
                                    ->isRequired()
                                    ->cannotBeEmpty()
                                ->end()
                                ->scalarNode('max_idle_retention_duration')
                                    ->isRequired()
                                    ->cannotBeEmpty()
                                ->end()
                                ->arrayNode('policies')
                                    ->isRequired()
                                    ->cannotBeEmpty()
                                    ->arrayPrototype()
                                        ->children()
                                            ->booleanNode('creat')
                                                ->defaultFalse()
                                            ->end()
                                            ->booleanNode('open')
                                                ->defaultFalse()
                                            ->end()
                                            ->booleanNode('download')
                                                ->defaultFalse()
                                            ->end()
                                            ->booleanNode('rename')
                                                ->defaultFalse()
                                            ->end()
                                            ->booleanNode('work')
                                                ->defaultFalse()
                                            ->end()
                                        ->end()
                                    ->end()
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }
}
