<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Service;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

class DatasystemProviderServiceCompilerPass implements CompilerPassInterface
{
    private const TAG = 'dbp.relay.blob.datasystem_provider_service';

    public static function register(ContainerBuilder $container): void
    {
        $container->registerForAutoconfiguration(DatasystemProviderServiceInterface::class)->addTag(self::TAG);
        $container->addCompilerPass(new DatasystemProviderServiceCompilerPass());
    }

    public function process(ContainerBuilder $container): void
    {
        if (!$container->has(DatasystemProviderService::class)) {
            return;
        }
        $definition = $container->findDefinition(DatasystemProviderService::class);
        $taggedServices = $container->findTaggedServiceIds(self::TAG);
        foreach ($taggedServices as $id => $tags) {
            $definition->addMethodCall('addService', [new Reference($id)]);
        }
    }
}
