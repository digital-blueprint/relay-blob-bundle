<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\TestUtils;

use Dbp\Relay\BlobBundle\DependencyInjection\DbpRelayBlobExtension;
use Dbp\Relay\BlobBundle\Entity\FileData;
use Dbp\Relay\CoreBundle\TestUtils\TestEntityManager as CoreTestEntityManager;
use Doctrine\ORM\EntityManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

class TestEntityManager extends CoreTestEntityManager
{
    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container, DbpRelayBlobExtension::ENTITY_MANAGER_ID, FileData::class);
    }

    public static function setUpBlobEntityManager(ContainerInterface $container): EntityManager
    {
        return self::setUpEntityManager($container, DbpRelayBlobExtension::ENTITY_MANAGER_ID);
    }

    public function getFileDataById(string $identifier): ?FileData
    {
        return $this->getEntityByIdentifier($identifier);
    }
}
