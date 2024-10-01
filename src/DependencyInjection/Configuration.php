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
                        // ->cannotBeEmpty()
                        ->defaultValue('%env(resolve:BLOB_DATABASE_NAME)%')
                    ->end()
                    ->scalarNode('reporting_interval')
                        ->isRequired()
                        ->defaultValue('0 9 * * MON')
                    ->end()
                    ->scalarNode('quota_warning_interval')
                        ->isRequired()
                        ->defaultValue('0 6 * * *')
                    ->end()
                    ->scalarNode('cleanup_interval')
                        ->isRequired()
                        ->defaultValue('0 * * * *')
                    ->end()
                    ->scalarNode('file_integrity_checks')
                        ->isRequired()
                        ->defaultValue(false)
                    ->end()
                    ->scalarNode('additional_auth')
                        ->isRequired()
                        ->defaultValue(false)
                    ->end()
                    ->scalarNode('integrity_check_interval')
                        ->isRequired()
                        ->defaultValue('0 0 1 * *')
                    ->end()
                    ->scalarNode('bucket_size_check_interval')
                        ->isRequired()
                        ->defaultValue('0 2 * * 1')
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
                                ->scalarNode('internal_bucket_id')
                                    ->isRequired()
                                    ->cannotBeEmpty()
                                ->end()
                                ->scalarNode('key')
                                    ->isRequired()
                                    ->cannotBeEmpty()
                                ->end()
                                ->scalarNode('quota')
                                    ->isRequired()
                                    ->cannotBeEmpty()
                                ->end()
                                ->scalarNode('output_validation')
                                    ->isRequired()
                                    ->defaultValue(true)
                                ->end()
                                ->scalarNode('notify_when_quota_over')
                                    ->isRequired()
                                    ->cannotBeEmpty()
                                ->end()
                                ->scalarNode('report_when_expiry_in')
                                    ->isRequired()
                                    ->cannotBeEmpty()
                                ->end()
                                ->scalarNode('bucket_owner')
                                    ->isRequired()
                                    ->cannotBeEmpty()
                                ->end()
                                ->scalarNode('link_expire_time')
                                    ->isRequired()
                                    ->defaultValue('PT1M')
                                ->end()
                                ->arrayNode('warn_quota')
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
                                        ->defaultValue('emails/warn-quota.html.twig')
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
                                ->arrayNode('integrity')
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
                                        ->defaultValue('emails/integrity.html.twig')
                                        ->end()
                                    ->end()
                                ->end()
                                ->arrayNode('bucket_size')
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
                                        ->defaultValue('emails/bucketsize.html.twig')
                                        ->end()
                                    ->end()
                                ->end()
                                ->variableNode('additional_types')
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }
}
