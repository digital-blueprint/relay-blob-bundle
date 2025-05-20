<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\TestUtils;

use Dbp\Relay\BlobBundle\Entity\FileData;
use Symfony\Component\DependencyInjection\ContainerInterface;

class TestEntityManager extends \Dbp\Relay\CoreBundle\TestUtils\TestEntityManager
{
    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container, 'dbp_relay_blob_bundle', FileData::class);
    }

    public function getFileDataById(string $identifier): ?FileData
    {
        return $this->getEntityByIdentifier($identifier);
    }
}
