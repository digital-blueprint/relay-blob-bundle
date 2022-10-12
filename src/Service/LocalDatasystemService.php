<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Service;

use Dbp\Relay\BlobBundle\Entity\Bucket;
use Dbp\Relay\BlobBundle\Entity\FileData;
use Dbp\Relay\BlobBundle\Helper\PoliciesStruct;
use Dbp\Relay\CoreBundle\Exception\ApiError;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Response;

class LocalDatasystemService implements DatasystemProviderServiceInterface
{
    /**
     * @var EntityManagerInterface
     */
    private $em;

    /** @var EventDispatcherInterface */
    private $dispatcher;

    public function __construct(EntityManagerInterface $em, EventDispatcherInterface $dispatcher)
    {
        $this->em = $em;
        $this->dispatcher = $dispatcher;
    }

    public function checkConnection()
    {
        $this->em->getConnection()->connect();
    }

    public function saveFile(FileData &$fileData): ?FileData
    {
        try {
            $this->em->persist($fileData);
            $this->em->flush();
        } catch (\Exception $e) {
            throw ApiError::withDetails(Response::HTTP_INTERNAL_SERVER_ERROR, 'File could not be created!', 'blob:form-not-created', ['message' => $e->getMessage()]);
        }

        return null;
    }

    public function renameFile(FileData &$fileData): ?FileData
    {
        return null;
    }

    public function getLink(FileData &$fileData, PoliciesStruct $policiesStruct): ?FileData
    {
        return null;
    }

    public function removeFile(FileData &$fileData): bool
    {
        return true;
    }

    public function removePathFromBucket(string $path, Bucket $bucket): bool
    {
        return true;
    }

    public function removeBucket(Bucket $bucket): bool
    {
        return true;
    }
}
