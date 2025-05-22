<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\TestUtils;

use Dbp\Relay\BlobBundle\DependencyInjection\DbpRelayBlobExtension;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

class BlobApiTest
{
    public static function setUp(ContainerInterface $container): EntityManagerInterface
    {
        return TestEntityManager::setUpEntityManager($container, DbpRelayBlobExtension::ENTITY_MANAGER_ID);
    }

    public static function tearDown(): void
    {
        TestDatasystemProviderService::cleanup();
    }
}
