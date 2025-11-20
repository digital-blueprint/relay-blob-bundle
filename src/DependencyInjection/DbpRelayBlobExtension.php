<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\DependencyInjection;

use Dbp\Relay\BlobBundle\ApiPlatform\FileDataProvider;
use Dbp\Relay\BlobBundle\Authorization\AuthorizationService;
use Dbp\Relay\BlobBundle\Configuration\ConfigurationService;
use Dbp\Relay\BlobBundle\Helper\BlobUuidBinaryType;
use Dbp\Relay\BlobBundle\Task\MetadataBackupTask;
use Dbp\Relay\BlobBundle\Task\MetadataRestoreTask;
use Dbp\Relay\CoreBundle\Doctrine\DoctrineConfiguration;
use Dbp\Relay\CoreBundle\Extension\ExtensionTrait;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\HttpKernel\DependencyInjection\ConfigurableExtension;

class DbpRelayBlobExtension extends ConfigurableExtension implements PrependExtensionInterface
{
    use ExtensionTrait;

    public const ENTITY_MANAGER_ID = 'dbp_relay_blob_bundle';

    public function loadInternal(array $mergedConfig, ContainerBuilder $container): void
    {
        $this->addResourceClassDirectory($container, __DIR__.'/../Entity');

        $loader = new YamlFileLoader(
            $container,
            new FileLocator(__DIR__.'/../Resources/config')
        );
        $loader->load('services.yaml');

        $definition = $container->getDefinition(ConfigurationService::class);
        $definition->addMethodCall('setConfig', [$mergedConfig]);

        $definition = $container->getDefinition(AuthorizationService::class);
        $definition->addMethodCall('setConfig', [$mergedConfig]);

        $typeDefinition = $container->getParameter('doctrine.dbal.connection_factory.types');
        $typeDefinition['relay_blob_uuid_binary'] = ['class' => BlobUuidBinaryType::class];
        $container->setParameter('doctrine.dbal.connection_factory.types', $typeDefinition);

        $definition = $container->getDefinition(FileDataProvider::class);
        $definition->addMethodCall('setConfig', [$mergedConfig]);
    }

    public function prepend(ContainerBuilder $container): void
    {
        $this->addQueueMessageClass($container, MetadataBackupTask::class);
        $this->addQueueMessageClass($container, MetadataRestoreTask::class);
        $configs = $container->getExtensionConfig($this->getAlias());
        $config = $this->processConfiguration(new Configuration(), $configs);

        DoctrineConfiguration::prependEntityManagerConfig($container, self::ENTITY_MANAGER_ID,
            $config[Configuration::DATABASE_URL] ?? '',
            __DIR__.'/../Entity',
            'Dbp\Relay\BlobBundle\Entity',
            self::ENTITY_MANAGER_ID);
        DoctrineConfiguration::prependMigrationsConfig($container,
            __DIR__.'/../Migrations',
            'Dbp\Relay\BlobBundle\Migrations');
    }
}
