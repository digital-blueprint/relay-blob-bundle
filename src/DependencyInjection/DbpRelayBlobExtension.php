<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\DependencyInjection;

use Dbp\Relay\BlobBundle\Helper\BlobUuidBinaryType;
use Dbp\Relay\BlobBundle\Service\ConfigurationService;
use Dbp\Relay\CoreBundle\Extension\ExtensionTrait;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\HttpKernel\DependencyInjection\ConfigurableExtension;

class DbpRelayBlobExtension extends ConfigurableExtension implements PrependExtensionInterface
{
    use ExtensionTrait;

    public function loadInternal(array $mergedConfig, ContainerBuilder $container): void
    {
        $loader = new YamlFileLoader(
            $container,
            new FileLocator(__DIR__.'/../Resources/config')
        );
        $loader->load('services.yaml');

        $definition = $container->getDefinition(ConfigurationService::class);
        $definition->addMethodCall('setConfig', [$mergedConfig]);

        $typeDefinition = $container->getParameter('doctrine.dbal.connection_factory.types');
        $typeDefinition['relay_blob_uuid_binary'] = ['class' => BlobUuidBinaryType::class];
        $container->setParameter('doctrine.dbal.connection_factory.types', $typeDefinition);
    }

    public function prepend(ContainerBuilder $container): void
    {
        $configs = $container->getExtensionConfig($this->getAlias());
        $config = $this->processConfiguration(new Configuration(), $configs);

        $this->addAllowHeader($container, 'X-Dbp-Signature');

        foreach (['doctrine', 'doctrine_migrations'] as $extKey) {
            if (!$container->hasExtension($extKey)) {
                throw new \Exception("'".$this->getAlias()."' requires the '$extKey' bundle to be loaded!");
            }
        }

        $container->prependExtensionConfig('doctrine', [
            'dbal' => [
                'connections' => [
                    'dbp_relay_blob_bundle' => [
                        'url' => $config['database_url'] ?? 'sqlite:///var/dbp_relay_blob_test.db',
                    ],
                ],
            ],
            'orm' => [
                'entity_managers' => [
                    'dbp_relay_blob_bundle' => [
                        'naming_strategy' => 'doctrine.orm.naming_strategy.underscore_number_aware',
                        'connection' => 'dbp_relay_blob_bundle',
                        'mappings' => [
                            'dbp_relay_blob' => [
                                'type' => 'annotation',
                                'dir' => __DIR__.'/../Entity',
                                'prefix' => 'Dbp\Relay\BlobBundle\Entity',
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $this->registerEntityManager($container, 'dbp_relay_blob_bundle');

        $container->prependExtensionConfig('doctrine_migrations', [
            'migrations_paths' => [
                'Dbp\Relay\BlobBundle\Migrations' => __DIR__.'/../Migrations',
            ],
        ]);
    }
}
