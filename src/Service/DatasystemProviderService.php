<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Service;

use Dbp\Relay\BlobBundle\Entity\Bucket;
use Symfony\Component\DependencyInjection\ContainerInterface;

class DatasystemProviderService
{
    /**
     * @var ContainerInterface
     */
    private $container;

    public function __construct(
        ContainerInterface $container
    ) {
        $this->container = $container;
    }

    public function getServiceByBucket(Bucket $bucket): DatasystemProviderServiceInterface
    {
        $service = $bucket->getService();

        $datasystemService = $this->container->get($service);
        assert($datasystemService instanceof DatasystemProviderServiceInterface);

        return $datasystemService;
    }
}
