<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\TestUtils;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

class BlobApiTest
{
    public static function setUp(ContainerInterface $container): EntityManagerInterface
    {
        return TestEntityManager::setUpEntityManager($container);
    }

    public static function tearDown(ContainerInterface $container): void
    {
    }
}
