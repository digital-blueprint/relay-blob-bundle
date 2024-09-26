<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Service;

use Dbp\Relay\BlobBundle\Entity\Bucket;

/**
 * @internal
 */
class DatasystemProviderService
{
    /**
     * @var array<class-string,DatasystemProviderServiceInterface>
     */
    private array $services;

    public function __construct()
    {
        $this->services = [];
    }

    public function addService(DatasystemProviderServiceInterface $service): void
    {
        $this->services[$service::class] = $service;
    }

    /**
     * Gets the datasystem Service of the bucket object.
     */
    public function getServiceByBucket(Bucket $bucket): DatasystemProviderServiceInterface
    {
        $serviceClass = $bucket->getService();

        $datasystemService = $this->services[$serviceClass] ?? null;
        if ($datasystemService === null) {
            throw new \RuntimeException("$serviceClass not found");
        }
        assert($datasystemService instanceof DatasystemProviderServiceInterface);

        return $datasystemService;
    }
}
