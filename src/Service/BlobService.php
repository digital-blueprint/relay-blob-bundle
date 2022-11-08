<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Service;

use Dbp\Relay\BlobBundle\Entity\FileData;
use Dbp\Relay\CoreBundle\Exception\ApiError;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\TextUI\XmlConfiguration\File;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

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

    /**
     * @var DatasystemProviderService
     */
    private $datasystemService;

    public function __construct(ManagerRegistry $managerRegistry, ConfigurationService $configurationService, DatasystemProviderService $datasystemService)
    {
        $manager = $managerRegistry->getManager('dbp_relay_blob_bundle');
        assert($manager instanceof EntityManagerInterface);
        $this->em = $manager;

        $this->configurationService = $configurationService;
        $this->datasystemService = $datasystemService;
    }

    public function checkConnection()
    {
        $this->em->getConnection()->connect();
    }

    public function createFileData(Request $request): FileData
    {
        $fileData = new FileData();

        $fileData->setIdentifier((string) Uuid::v4());

        /** @var ?UploadedFile $uploadedFile */
        $uploadedFile = $request->files->get('file');

        // check if there is an uploaded file
        if (!$uploadedFile) {
            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'No file with parameter key "file" was received!', 'blob:create-file-missing-file');
        }

        // If the upload failed, figure out why
        if ($uploadedFile->getError() !== UPLOAD_ERR_OK) {
            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, $uploadedFile->getErrorMessage(), 'blob:create-file-upload-error');
        }

        // check if file is empty
        if ($uploadedFile->getSize() === 0) {
            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'Empty files cannot be added!', 'blob:create-file-empty-files-not-allowed');
        }

        $fileData->setFile($uploadedFile);

        $fileData->setPrefix($request->get('prefix'));
        $fileData->setFileName($request->get('fileName'));

        $time = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $fileData->setDateCreated($time);
        $fileData->setLastAccess($time);

        // Check if json is valid
        $metadata = $request->get('additionalMetadata');
        try {
            json_decode($metadata, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw ApiError::withDetails(Response::HTTP_UNPROCESSABLE_ENTITY, 'The addtional Metadata doesn\'t contain valid json!', 'blob:blob-service-invalid-json');
        }
        $fileData->setAdditionalMetadata($metadata);

        $fileData->setBucketID($request->get('bucketID'));
        $fileData->setRetentionDuration($request->get('retentionDuration'));

        return $fileData;
    }

    public function saveFileData(FileData $fileData): void
    {
        try {
            $this->em->persist($fileData);
            $this->em->flush();
        } catch (\Exception $e) {
            throw ApiError::withDetails(Response::HTTP_INTERNAL_SERVER_ERROR, 'File could not be saved!', 'blob:file-not-saved', ['message' => $e->getMessage()]);
        }
    }

    public function saveFile(FileData $fileData): ?FileData
    {
        $datasystemService = $this->datasystemService->getServiceByBucket($fileData->getBucket());
        $fileData = $datasystemService->saveFile($fileData);

        return $fileData;
    }

    public function getLink(FileData $fileData): ?FileData
    {
        $datasystemService = $this->datasystemService->getServiceByBucket($fileData->getBucket());
        $fileData = $datasystemService->getLink($fileData, $fileData->getBucket()->getPolicies());

        return $fileData;
    }


    public function getFileData(string $identifier): FileData
    {
        $fileData = $this->em
            ->getRepository(FileData::class)
            ->find($identifier);

        if (!$fileData) {
            throw ApiError::withDetails(Response::HTTP_NOT_FOUND, 'FileData was not found!', 'blob:fileData-not-found');
        }

        return $fileData;
    }

    public function getFileDataByBucketIDAndPrefix(string $bucketID, string $prefix): array
    {
        $fileDatas = $this->em
            ->getRepository(FileData::class)
            ->findBy(['bucketID' => $bucketID, 'prefix' => $prefix]);

        if (!$fileDatas) {
            throw ApiError::withDetails(Response::HTTP_NOT_FOUND, 'FileDatas was not found!', 'blob:fileDatas-not-found');
        }

        return $fileDatas;
    }
}
