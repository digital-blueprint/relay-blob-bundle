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
                                ->scalarNode('bucket_owner')
                                    ->isRequired()
                                    ->cannotBeEmpty()
                                ->end()
                                ->scalarNode('max_retention_duration')
                                    ->isRequired()
                                    ->cannotBeEmpty()
                                ->end()
                                ->scalarNode('link_expire_time')
                                ->end()
                                ->arrayNode('policies')
                                    ->scalarPrototype()->end()
                                ->end()
                                ->arrayNode('notify_quota')
                                    ->children()
                                        ->scalarNode('dsn')
                                        ->end()
                                        ->scalarNode('from')
                                        ->end()
                                        ->scalarNode('to')
                                        ->end()
                                        ->scalarNode('subject')
                                        ->end()
                                        ->scalarNode('html_template')
                                        ->defaultValue('emails/notify-quota.html.twig')
                                        ->end()
                                    ->end()
                                ->end()
                                ->arrayNode('reporting')
                                    ->children()
                                        ->scalarNode('dsn')
                                        ->end()
                                        ->scalarNode('from')
                                        ->end()
                                        ->scalarNode('to')
                                        ->end()
                                        ->scalarNode('subject')
                                        ->end()
                                        ->scalarNode('html_template')
                                        ->defaultValue('emails/reporting.html.twig')
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
