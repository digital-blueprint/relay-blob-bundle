<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Tests;

use Dbp\Relay\BlobBundle\DbpRelayBlobBundle;
use Dbp\Relay\BlobBundle\MessageHandler\MessageHandler;
use Dbp\Relay\BlobBundle\TestUtils\BlobTestUtils;
use Dbp\Relay\CoreBundle\TestUtils\CoreTestKernelTrait;
use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Doctrine\Bundle\MigrationsBundle\DoctrineMigrationsBundle;
use Doctrine\DBAL\Exception;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use CoreTestKernelTrait;

    protected function registerAdditionalBundles(): iterable
    {
        yield new DoctrineBundle();
        yield new DoctrineMigrationsBundle();
        yield new DbpRelayBlobBundle();
    }

    /**
     * @throws Exception
     */
    protected function configureAdditionalContainer(ContainerConfigurator $container): void
    {
        $container->extension('dbp_relay_blob', BlobTestUtils::getTestConfig());
        $container->services()->set(MessageHandler::class)
            ->tag('messenger.message_handler')
            ->public();
    }
}
