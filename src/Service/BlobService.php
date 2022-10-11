<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Service;

use Dbp\Relay\BlobBundle\Entity\FileData;
use Dbp\Relay\CoreBundle\Exception\ApiError;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\HttpFoundation\Response;

class BlobService
{
    /**
     * @var EntityManagerInterface
     */
    private $em;

    /**
     * @var ConfigurationService
     */
    public $configurationService; //TODO maybe private


    public function __construct(ManagerRegistry $managerRegistry, ConfigurationService $configurationService)
    {
        $manager = $managerRegistry->getManager('dbp_relay_blob_bundle');
        assert($manager instanceof EntityManagerInterface);
        $this->em = $manager;

        $this->configurationService = $configurationService;
    }

    public function checkConnection()
    {
        $this->em->getConnection()->connect();
    }

    public function createFileData(FileData $fileData, string $fileDataIdentifier, string $contentUrl): FileData
    {
        //check additional metada valid json
        //check bucket ID exists
        //check retentionDuration & idleRetentionDuration valid durations


   /*

        try {
            $this->em->persist($fileData);
            $this->em->flush();
        } catch (\Exception $e) {
            throw ApiError::withDetails(Response::HTTP_INTERNAL_SERVER_ERROR, 'File could not be created!yuhuu', 'blob:submission-not-created', ['message' => $e->getMessage()]);
        }
        throw ApiError::withDetails(Response::HTTP_INTERNAL_SERVER_ERROR, 'Alles doof', 'blob:alles-doof');
*/
        return $fileData;
    }
}
