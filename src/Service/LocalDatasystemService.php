<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Service;

use Dbp\Relay\BlobBundle\Entity\FileData;
use Dbp\Relay\CoreBundle\Exception\ApiError;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Response;

class LocalDatasystemService
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

    public function saveFile(FileData $fileData, string $fileDataIdentifier, string $contentUrl): FileData
    {
        //check additional metada valid json
        //check bucket ID exists
        //check retentionDuration & idleRetentionDuration valid durations

        $fileData->setIdentifier($fileDataIdentifier);
        $fileData->setContentUrl($contentUrl);

        try {
            $this->em->persist($fileData);
            $this->em->flush();
        } catch (\Exception $e) {
            throw ApiError::withDetails(Response::HTTP_INTERNAL_SERVER_ERROR, 'File could not be created!', 'blob:form-not-created', ['message' => $e->getMessage()]);
        }
        return $fileData;
    }
}
