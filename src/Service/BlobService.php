<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Service;

use Dbp\Relay\BlobBundle\Configuration\BucketConfig;
use Dbp\Relay\BlobBundle\Configuration\ConfigurationService;
use Dbp\Relay\BlobBundle\Entity\BucketSize;
use Dbp\Relay\BlobBundle\Entity\FileData;
use Dbp\Relay\BlobBundle\Event\AddFileDataByPostSuccessEvent;
use Dbp\Relay\BlobBundle\Event\ChangeFileDataByPatchSuccessEvent;
use Dbp\Relay\BlobBundle\Event\DeleteFileDataByDeleteSuccessEvent;
use Dbp\Relay\BlobBundle\Helper\BlobUtils;
use Dbp\Relay\BlobBundle\Helper\BlobUuidBinaryType;
use Dbp\Relay\BlobLibrary\Api\BlobApi;
use Dbp\Relay\BlobLibrary\Helpers\SignatureTools;
use Dbp\Relay\CoreBundle\Doctrine\QueryHelper;
use Dbp\Relay\CoreBundle\Exception\ApiError;
use Dbp\Relay\CoreBundle\Helpers\Tools;
use Dbp\Relay\CoreBundle\Rest\Query\Filter\Filter;
use Dbp\Relay\CoreBundle\Rest\Query\Pagination\Pagination;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use JsonSchema\Validator;
use Psr\Http\Message\StreamInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

date_default_timezone_set('UTC');

/**
 * @internal
 */
class BlobService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    public const UPDATE_LAST_ACCESS_TIMESTAMP_OPTION = 'update_last_access_timestamp';
    public const ASSERT_BUCKET_ID_EQUALS_OPTION = 'assert_bucket_id_equals';
    public const BASE_URL_OPTION = 'base_url';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ConfigurationService $configurationService,
        private DatasystemProviderService $datasystemService,
        private readonly EventDispatcherInterface $eventDispatcher
    ) {
    }

    public function checkConnection(): void
    {
        $this->entityManager->getConnection()->getNativeConnection();
    }

    public function getConfigurationService(): ConfigurationService
    {
        return $this->configurationService;
    }

    /**
     * @throws \Exception
     */
    public function addFile(FileData $fileData, array $options = []): FileData
    {
        if ($fileData->getFile() === null) {
            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'file is missing', 'blob:create-file-data-file-missing');
        }

        $fileData->setIdentifier(Uuid::v7()->toRfc4122());

        $now = BlobUtils::now();
        $fileData->setDateCreated($now);
        $fileData->setDateAccessed($now);
        $fileData->setDateModified($now);

        $errorPrefix = 'blob:create-file-data';
        $this->ensureFileDataIsValid($fileData, $errorPrefix);

        // write all relevant data to tables
        $this->saveFileDataAndFile($fileData, $fileData->getFileSize(), $errorPrefix);

        $fileData->setContentUrl($this->getDownloadUrl($options[self::BASE_URL_OPTION] ?? '', $fileData));

        /* dispatch POST success event */
        $successEvent = new AddFileDataByPostSuccessEvent($fileData);
        $this->eventDispatcher->dispatch($successEvent);

        return $fileData;
    }

    /**
     * @throws \Exception
     */
    public function updateFile(FileData $fileData, FileData $previousFileData): FileData
    {
        $now = BlobUtils::now();
        $fileData->setDateAccessed($now);
        $fileData->setDateModified($now);

        $errorPrefix = 'blob:patch-file-data';
        $this->ensureFileDataIsValid($fileData, $errorPrefix);

        if ($fileData->getFile() !== null) {
            $this->saveFileDataAndFile($fileData, $fileData->getFileSize() - $previousFileData->getFileSize(), $errorPrefix);
        } else {
            $this->saveFileData($fileData);
        }

        $patchSuccessEvent = new ChangeFileDataByPatchSuccessEvent($fileData);
        $this->eventDispatcher->dispatch($patchSuccessEvent);

        return $fileData;
    }

    /**
     * @throws \Exception
     */
    public function removeFile(FileData $fileData, array $options = []): void
    {
        $bucketConfig = $this->getBucketConfig($fileData->getInternalBucketId());

        if (($bucketIdToMatch = $options[self::ASSERT_BUCKET_ID_EQUALS_OPTION] ?? null) !== null) {
            if ($bucketConfig->getBucketId() !== $bucketIdToMatch) {
                throw new ApiError(Response::HTTP_FORBIDDEN);
            }
        }

        $this->removeFileDataAndFile($fileData, -$fileData->getFileSize());

        $deleteSuccessEvent = new DeleteFileDataByDeleteSuccessEvent($fileData);
        $this->eventDispatcher->dispatch($deleteSuccessEvent);
    }

    /**
     * @throws \Exception
     */
    public function getFileData(string $identifier, array $options = []): FileData
    {
        $fileData = $this->getFileDataInternal($identifier);
        $bucketConfig = $this->getBucketConfig($fileData->getInternalBucketId());

        if (($bucketIdToMatch = $options[self::ASSERT_BUCKET_ID_EQUALS_OPTION] ?? null) !== null) {
            if ($bucketConfig->getBucketId() !== $bucketIdToMatch) {
                throw new ApiError(Response::HTTP_FORBIDDEN);
            }
        }

        if ($fileData->getDeleteAt() !== null
            && (($options[BlobApi::INCLUDE_DELETE_AT_OPTION] ?? false) === false || $fileData->getDeleteAt() < BlobUtils::now())) {
            throw ApiError::withDetails(Response::HTTP_NOT_FOUND,
                'FileData was not found!', 'blob:file-data-not-found');
        }

        if (($options[BlobApi::DISABLE_OUTPUT_VALIDATION_OPTION] ?? false) === false && $bucketConfig->getOutputValidation()) {
            $this->checkFileDataBeforeRetrieval($fileData, 'blob:get-file-data');
        }

        if ($options[self::UPDATE_LAST_ACCESS_TIMESTAMP_OPTION] ?? true) {
            $fileData->setDateAccessed(BlobUtils::now());
            $this->saveFileData($fileData);
        }

        $fileData->setContentUrl($options[BlobApi::INCLUDE_FILE_CONTENTS_OPTION] ?? false ?
            $this->getContentUrl($fileData) :
            $this->getDownloadUrl($options[self::BASE_URL_OPTION] ?? '', $fileData));

        return $fileData;
    }

    /**
     * @throws \Exception
     */
    public function getFiles(string $bucketIdentifier, ?Filter $filter = null, array $options = [],
        int $currentPageNumber = 1, int $maxNumItemsPerPage = 30): array
    {
        // TODO: make the upper limit configurable
        if ($maxNumItemsPerPage > 1000) {
            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST,
                'Requested too many items per page',
                'blob:get-file-data-collection-too-many-items-per-page'
            );
        }

        $fileDataCollection = $this->getFileDataCollection(
            $this->getInternalBucketIdByBucketID($bucketIdentifier), $filter,
            Pagination::getFirstItemIndex($currentPageNumber, $maxNumItemsPerPage), $maxNumItemsPerPage,
            $options[BlobApi::INCLUDE_DELETE_AT_OPTION] ?? false);

        $includeFileContents = $options[BlobApi::INCLUDE_FILE_CONTENTS_OPTION] ?? false;
        $baseUrl = $options[self::BASE_URL_OPTION] ?? '';

        foreach ($fileDataCollection as $fileData) {
            assert($fileData instanceof FileData);
            $fileData->setBucketId($bucketIdentifier);
            $fileData->setContentUrl($includeFileContents ?
                $this->getContentUrl($fileData) :
                $this->getDownloadUrl($baseUrl, $fileData));
        }

        return $fileDataCollection;
    }

    /**
     * Creates a new fileData element and saves all the data from the request in it, if the request is valid.
     *
     * @param Request $request request which provides all the data
     *
     * @throws \Exception
     */
    public function setUpFileDataFromRequest(FileData $fileData, Request $request, string $errorPrefix): FileData
    {
        // check content-length header to prevent misleading error messages if the upload is too big for the server to accept
        if (($contentLength = $request->headers->get('Content-Length') ?? null) !== null
            && intval($contentLength) > BlobUtils::convertFileSizeStringToBytes(ini_get('post_max_size'))) {
            throw ApiError::withDetails(
                Response::HTTP_BAD_REQUEST,
                'Given file is too large',
                $errorPrefix.'-file-too-big'
            );
        }

        /* get the url query params */
        $bucketIdentifier = $request->query->get('bucketIdentifier');
        $prefix = $request->query->get('prefix');
        $notifyEmail = $request->query->get('notifyEmail');
        $deleteIn = $request->query->get('deleteIn');
        $type = $request->query->get('type');

        /* get the params from body */
        $metadata = $request->request->get('metadata');
        $fileName = $request->request->get('fileName');
        $fileHash = $request->request->get('fileHash');
        $metadataHash = $request->request->get('metadataHash');

        /* get the file */
        /** @var ?UploadedFile $uploadedFile */
        $uploadedFile = $request->files->get('file');

        $fileData->setBucketId($bucketIdentifier);

        if ($uploadedFile !== null) {
            if ($uploadedFile instanceof UploadedFile && $uploadedFile->getError() !== UPLOAD_ERR_OK) {
                throw ApiError::withDetails(
                    Response::HTTP_BAD_REQUEST,
                    $uploadedFile->getErrorMessage(),
                    $errorPrefix.'-upload-error'
                );
            }
            if ($uploadedFile->getSize() === 0) {
                throw ApiError::withDetails(
                    Response::HTTP_BAD_REQUEST,
                    'Empty files cannot be added!',
                    $errorPrefix.'-empty-files-not-allowed'
                );
            }
            if ($fileHash !== null && $fileHash !== \hash_file('sha256', $uploadedFile->getPathname())) {
                throw ApiError::withDetails(
                    Response::HTTP_FORBIDDEN,
                    'File hash change forbidden',
                    $errorPrefix.'-file-hash-change-forbidden'
                );
            }
            $fileData->setFile($uploadedFile);
        }

        if ($metadataHash !== null) {
            if ($metadataHash !== hash('sha256', $metadata)) {
                throw ApiError::withDetails(
                    Response::HTTP_FORBIDDEN,
                    'Metadata hash change forbidden',
                    $errorPrefix.'-metadata-hash-change-forbidden'
                );
            }
        }
        if ($fileName !== null) {
            $fileData->setFileName($fileName);
        }
        if ($prefix) {
            $fileData->setPrefix($prefix);
        }
        if ($deleteIn !== null) {
            if ($deleteIn === 'null') {
                $fileData->setDeleteAt(null);
            } else {
                $fileData->setDeleteAt(BlobUtils::now()->add(new \DateInterval($deleteIn)));
            }
        }
        if ($notifyEmail !== null) {
            $fileData->setNotifyEmail($notifyEmail);
        }
        if ($type !== null) {
            $fileData->setType($type);
        }
        if ($metadata !== null) {
            $fileData->setMetadata($metadata);
        }
        if ($fileHash !== null) {
            $fileData->setFileHash($fileHash);
        }

        return $fileData;
    }

    /**
     * Creates a new dummy fileData element and file of given size.
     * Should be used for testing purposes only.
     *
     * @param string $filesize         number of bytes the file should have
     * @param string $bucketIdentifier bucket identifier of bucket in which the file should be saved
     * @param string $prefix           prefix saved in the db
     * @param string $fileName         filename of the generated file
     * @param string $deleteIn         duration after which the file should be deleted
     * @param string $metadata         user-defined metadata of the file
     * @param string $type             metadata type which will be validated against and saved in the db
     *
     * @throws \Exception
     */
    public function generateAndSaveDummyFileDataAndFile(string $filesize, string $bucketIdentifier, string $prefix, string $fileName, string $deleteIn, string $metadata, string $type): FileData
    {
        $fileData = new FileData();
        $fileData->setBucketId($bucketIdentifier);
        $fileData->setFileName($fileName);
        $fileData->setPrefix($prefix);
        if (!empty($deleteIn)) {
            if ($deleteIn === 'null') {
                $fileData->setDeleteAt(null);
            } else {
                $fileData->setDeleteAt(BlobUtils::now()->add(new \DateInterval($deleteIn)));
            }
        }
        if (!empty($type)) {
            $fileData->setType($type);
        }
        if (!empty($metadata)) {
            $fileData->setMetadata($metadata);
        }

        // generate dummy file
        /** @var File $file */
        $file = $this->generateDummyFileWithGivenFilesize($filesize);
        $fileData->setFile($file);

        // add file to blob db and the storage system
        $this->addFile($fileData);

        return $fileData;
    }

    public function getInternalBucketIdByBucketID(string $bucketID): ?string
    {
        return $this->configurationService->getInternalBucketIdByBucketID($bucketID);
    }

    /**
     * Returns the bucket config for the given file.
     *
     * @throws \Exception
     */
    public function getBucketConfig(?string $internalBucketId): BucketConfig
    {
        if ($internalBucketId === null
            || ($bucket = $this->configurationService->getBucketByInternalID($internalBucketId)) === null) {
            throw new \Exception('failed to get bucket config for file data');
        }

        return $bucket;
    }

    /**
     * Used to persist given fileData in entity manager, and automatically adapts last access timestamp.
     *
     * @param FileData $fileData fileData to be persisted
     *
     * @throws \Exception
     */
    public function saveFileData(FileData $fileData): void
    {
        // try to persist fileData, or throw error
        try {
            $this->entityManager->persist($fileData);
            $this->entityManager->flush();
        } catch (\Exception $e) {
            throw ApiError::withDetails(
                Response::HTTP_INTERNAL_SERVER_ERROR,
                'File could not be saved!',
                'blob:file-not-saved',
                ['message' => $e->getMessage()]
            );
        }
    }

    /**
     * @throws \Exception
     */
    public function getBucketSizeByInternalIdFromDatabase(string $internalBucketId): BucketSize
    {
        $bucket = $this->entityManager->getRepository(BucketSize::class)->find($internalBucketId);

        if (!$bucket) {
            $newBucket = new BucketSize();
            $newBucket->setIdentifier($internalBucketId);
            $newBucket->setCurrentBucketSize(0);
            $this->entityManager->persist($newBucket);
            $this->entityManager->flush();
            $bucket = $newBucket;
        }

        return $bucket;
    }

    /**
     * Used to persist given fileData in entity manager, and automatically adapts last access timestamp.
     *
     * @param BucketSize $bucket bucket to be persisted
     *
     * @throws \Exception
     */
    public function saveBucketSize(BucketSize $bucket): void
    {
        try {
            $this->entityManager->persist($bucket);
            $this->entityManager->flush();
        } catch (\Exception $e) {
            throw ApiError::withDetails(Response::HTTP_INTERNAL_SERVER_ERROR, 'Bucket data could not be saved!', 'blob:file-not-saved', ['message' => $e->getMessage()]);
        }
    }

    public function updateBucketSize(BucketSize $bucket, int $bucketSizeDelta): void
    {
        try {
            $this->entityManager
                ->getRepository(BucketSize::class)
                ->createQueryBuilder('f')
                ->update()
                ->set('f.currentBucketSize', 'f.currentBucketSize + :bucketSizeDelta')
                ->where('f.identifier = :bucketID')
                ->setParameter('bucketID', $bucket->getIdentifier())
                ->setParameter('bucketSizeDelta', $bucketSizeDelta)
                ->getQuery()
                ->execute();
            $this->entityManager->flush();
        } catch (\Exception $e) {
            throw ApiError::withDetails(Response::HTTP_INTERNAL_SERVER_ERROR,
                'Bucket data could not be saved!', 'blob:file-not-saved', ['message' => $e->getMessage()]);
        }
    }

    public function recalculateAndUpdateBucketSize(string $intBucketId, ?OutputInterface $out = null): void
    {
        try {
            if (!is_null($out)) {
                $out->writeln('Calculating total size of bucket '.$intBucketId.' from blob_files table ...');
            }
            $query = $this->entityManager
                ->getRepository(FileData::class)
                ->createQueryBuilder('f')
                ->select('sum(f.fileSize)')
                ->where('f.internalBucketId = :bucketID')
                ->setParameter('bucketID', $intBucketId)
                ->getQuery()
                ->getOneOrNullResult();

            if (is_null($query) || count($query) !== 1) {
                throw ApiError::withDetails(Response::HTTP_INTERNAL_SERVER_ERROR, 'Total bucket size couldnt be calcualted!');
            }

            if (!is_null($out)) {
                $out->writeln('Calculated total size of '.$query[1].', updating blob_bucket_sizes table ...');
            }

            $query = $this->entityManager
                ->getRepository(BucketSize::class)
                ->createQueryBuilder('f')
                ->update()
                ->set('f.currentBucketSize', ':newBucketSize')
                ->where('f.identifier = :bucketID')
                ->setParameter('bucketID', $intBucketId)
                ->setParameter('newBucketSize', $query[1])
                ->getQuery()
                ->getResult();

            $this->entityManager->flush();

            if (!is_null($out)) {
                $out->writeln('Successfully updated blob_bucket_sizes table!');
            }
        } catch (\Exception $e) {
            throw ApiError::withDetails(Response::HTTP_INTERNAL_SERVER_ERROR, 'Bucket data could not be saved!', 'blob:file-not-saved', ['message' => $e->getMessage()]);
        }
    }

    /**
     * @throws ApiError
     */
    private function saveFileToDatasystemService(FileData $fileData, string $errorPrefix): void
    {
        try {
            $this->getDatasystemProvider($fileData->getInternalBucketId())->saveFile(
                $fileData->getInternalBucketId(),
                $fileData->getIdentifier(),
                $fileData->getFile()
            );
        } catch (\Exception $exception) {
            $this->logger->error(sprintf('saving file to datasystem service failed: %s', $exception->getMessage()));
            throw ApiError::withDetails(
                Response::HTTP_INTERNAL_SERVER_ERROR,
                'saving file to datasystem service failed',
                $errorPrefix.'-saving-file-failed'
            );
        }
    }

    /**
     * Creates an HTTP link to binary file content.
     *
     * @param FileData $fileData fileData for which a link should be provided
     *
     * @throws \Exception
     */
    public function getDownloadUrl(string $baseurl, FileData $fileData): string
    {
        $bucketConfig = $this->getBucketConfig($fileData->getInternalBucketId());

        return SignatureTools::createSignedUrl($bucketConfig->getBucketId(), $bucketConfig->getKey(),
            Request::METHOD_GET, $baseurl, $fileData->getIdentifier(), 'download');
    }

    /**
     * @throws ApiError
     */
    public function getFileContents(FileData $fileData): string
    {
        return $this->getFileStreamInternal($fileData)->getContents();
    }

    /**
     * Returns SHA256 hash of the file content.
     *
     * @throws \Exception
     */
    public function getFileHashFromStorage(FileData $fileData): string
    {
        return $this->getDatasystemProvider($fileData->getInternalBucketId())
            ->getFileHash($fileData->getInternalBucketId(), $fileData->getIdentifier());
    }

    /**
     * @throws \Exception
     */
    public function getFileSizeFromStorage(FileData $fileData): int
    {
        return $this->getDatasystemProvider($fileData->getInternalBucketId())
            ->getFileSize($fileData->getInternalBucketId(), $fileData->getIdentifier());
    }

    /**
     * Get file content as a base64 encoded content url.
     *
     * @param FileData $fileData fileData for which the base64 encoded file should be provided
     *
     * @throws ApiError
     */
    public function getContentUrl(FileData $fileData): string
    {
        return 'data:'.$fileData->getMimeType().';base64,'.base64_encode($this->getFileContents($fileData));
    }

    /**
     * Gets a streamed response for the file.
     *
     * @throws \Exception
     */
    public function getFileStream(FileData $fileData): StreamInterface
    {
        return $this->getFileStreamInternal($fileData);
    }

    private function getFileDataInternal(string $identifier): FileData
    {
        if (!Uuid::isValid($identifier)) {
            throw ApiError::withDetails(
                Response::HTTP_NOT_FOUND,
                'FileData was not found!',
                'blob:file-data-not-found'
            );
        }

        /** @var ?FileData $fileData */
        $fileData = $this->entityManager
            ->getRepository(FileData::class)
            ->find($identifier);

        if (!$fileData) {
            throw ApiError::withDetails(
                Response::HTTP_NOT_FOUND,
                'FileData was not found!',
                'blob:file-data-not-found'
            );
        }

        $fileData->setBucketId(
            $this->configurationService->getBucketIdByInternalBucketID($fileData->getInternalBucketId())
        );

        return $fileData;
    }

    /**
     * @throws \Exception
     */
    public function getFileDataCollection(string $internalBucketID, ?Filter $filter = null,
        int $firstItemIndex = 0, int $maxNumItemsPerPage = 30, bool $includeDeleteAt = false)
    {
        $FILE_DATA_ENTITY_ALIAS = 'f';

        $queryBuilder = $this->entityManager
            ->getRepository(FileData::class)
            ->createQueryBuilder($FILE_DATA_ENTITY_ALIAS);
        $queryBuilder = $queryBuilder
            ->where($queryBuilder->expr()->eq("$FILE_DATA_ENTITY_ALIAS.internalBucketId", ':bucketID'));

        if ($filter !== null) {
            QueryHelper::addFilter($queryBuilder, $filter, $FILE_DATA_ENTITY_ALIAS);
        }

        if ($includeDeleteAt) {
            $queryBuilder = $queryBuilder->andWhere(
                $queryBuilder->expr()->orX(
                    $queryBuilder->expr()->gt("$FILE_DATA_ENTITY_ALIAS.deleteAt", ':now'),
                    $queryBuilder->expr()->isNull("$FILE_DATA_ENTITY_ALIAS.deleteAt")))
                ->setParameter('now', BlobUtils::now());
        } else {
            $queryBuilder = $queryBuilder->andWhere($queryBuilder->expr()->isNull('f.deleteAt'));
        }

        $queryBuilder = $queryBuilder
            ->orderBy('f.dateCreated', 'ASC')
            ->setParameter('bucketID', $internalBucketID)
            ->setFirstResult($firstItemIndex)
            ->setMaxResults($maxNumItemsPerPage);

        return $queryBuilder->getQuery()->getResult();
    }

    /**
     * @return FileData[]
     *
     * @throws \Exception
     */
    public function getFileDataCollectionCursorBased(?string $lastIdentifier, int $maxNumItems = 1024, ?Filter $filter = null): array
    {
        if ($lastIdentifier === null) {
            $lastIdentifier = '00000000-0000-0000-0000-000000000000';
        }

        $FILE_DATA_ENTITY_ALIAS = 'f';
        $queryBuilder = $this->entityManager->createQueryBuilder();
        $queryBuilder
            ->select($FILE_DATA_ENTITY_ALIAS)
            ->from(FileData::class, $FILE_DATA_ENTITY_ALIAS)
            ->where($queryBuilder->expr()->gt("$FILE_DATA_ENTITY_ALIAS.identifier", ':lastIdentifier'))
            ->setParameter('lastIdentifier', $lastIdentifier, BlobUuidBinaryType::NAME)
            ->orderBy("$FILE_DATA_ENTITY_ALIAS.identifier", 'ASC');

        if ($filter !== null) {
            QueryHelper::addFilter($queryBuilder, $filter, $FILE_DATA_ENTITY_ALIAS);
        }

        return $queryBuilder
            ->getQuery()
            ->setMaxResults($maxNumItems)
            ->getResult();
    }

    /**
     * @throws \Exception
     */
    public function getDatasystemProvider(string $internalBucketId): DatasystemProviderServiceInterface
    {
        return $this->datasystemService->getService($this->getBucketConfig($internalBucketId)->getService());
    }

    /**
     * Remove a given fileData from the entity manager.
     */
    private function removeFileData(FileData $fileData): void
    {
        $this->entityManager->remove($fileData);
        $this->entityManager->flush();
    }

    private function removeFileFromDatasystemService(FileData $fileData): void
    {
        try {
            $this->getDatasystemProvider($fileData->getInternalBucketId())
                ->removeFile($fileData->getInternalBucketId(), $fileData->getIdentifier());
        } catch (\Exception $exception) {
            $this->logger->error(
                sprintf(
                    'failed to remove file \'%s\' from datasystem service: %s',
                    $fileData->getIdentifier(),
                    $exception->getMessage()
                )
            );
            throw ApiError::withDetails(
                Response::HTTP_INTERNAL_SERVER_ERROR,
                'Error while removing file from datasystem service',
                'blob:removing-file-from-datasystem-service-failed'
            );
        }
    }

    /**
     * Cleans the table from resources that exceeded their deleteAt date.
     *
     * @throws \Exception
     */
    public function cleanUp(): void
    {
        $now = BlobUtils::now();
        $maxNumItemsPerPage = 10000;

        do {
            $fileDataCollectionToDelete = $this->entityManager
                ->getRepository(FileData::class)
                ->createQueryBuilder('f')
                ->where('f.deleteAt IS NOT NULL')
                ->andWhere('f.deleteAt < :now')
                ->setParameter('now', $now)
                ->setMaxResults($maxNumItemsPerPage)
                ->getQuery()
                ->getResult();

            foreach ($fileDataCollectionToDelete as $fileDataToDelete) {
                $this->removeFile($fileDataToDelete);
            }
        } while (count($fileDataCollectionToDelete) === $maxNumItemsPerPage);
    }

    /**
     * @throws ApiError
     * @throws \Exception
     */
    public function saveFileDataAndFile(FileData $fileData, int $bucketSizeDeltaByte, string $errorPrefix): void
    {
        /* Check quota */
        $bucketQuotaByte = $this->getBucketConfig($fileData->getInternalBucketId())->getQuota() * 1024 * 1024; // Convert mb to Byte
        $bucketSize = $this->getBucketSizeByInternalIdFromDatabase($fileData->getInternalBucketId());
        $newBucketSizeByte = max($bucketSize->getCurrentBucketSize() + $bucketSizeDeltaByte, 0);
        if ($newBucketSizeByte > $bucketQuotaByte) {
            throw ApiError::withDetails(
                Response::HTTP_INSUFFICIENT_STORAGE,
                'Bucket quota is reached',
                $errorPrefix.'-bucket-quota-reached'
            );
        }
        $this->saveFileToDatasystemService($fileData, $errorPrefix);

        $this->entityManager->getConnection()->beginTransaction();
        try {
            $this->updateBucketSize($bucketSize, $bucketSizeDeltaByte);
            $this->saveFileData($fileData);
            $this->entityManager->getConnection()->commit();
        } catch (\Exception $e) {
            $this->logger->error(sprintf(
                'failed to save file data for \'%s\': %s',
                $fileData->getIdentifier(),
                $e->getMessage()
            ));
            $this->entityManager->getConnection()->rollBack();
            try {
                $this->removeFileFromDatasystemService($fileData);
            } catch (\Exception) {
            }
            throw ApiError::withDetails(
                Response::HTTP_INTERNAL_SERVER_ERROR,
                'Error while saving the file data',
                $errorPrefix.'-save-file-failed'
            );
        }
    }

    /**
     * @throws \Exception
     */
    public function removeFileDataAndFile(FileData $fileData, int $bucketSizeDeltaByte): void
    {
        $bucketSize = $this->getBucketSizeByInternalIdFromDatabase($fileData->getInternalBucketId());

        // try to update bucket size
        $this->entityManager->getConnection()->beginTransaction();
        try {
            $this->updateBucketSize($bucketSize, $bucketSizeDeltaByte);
            $this->removeFileData($fileData);
            $this->removeFileFromDatasystemService($fileData);

            $this->entityManager->getConnection()->commit();
        } catch (\Exception $exception) {
            $this->logger->error(
                sprintf(
                    'failed to save file \'%s\' to datasystem service: %s',
                    $fileData->getIdentifier(),
                    $exception->getMessage()
                )
            );
            $this->entityManager->getConnection()->rollBack();
            throw ApiError::withDetails(
                Response::HTTP_INTERNAL_SERVER_ERROR,
                'Error while removing the file data',
                'blob:remove-file-data-save-file-failed'
            );
        }
    }

    /**
     * @throws \Exception
     */
    public function validateMetadata(FileData $fileData, string $errorPrefix): void
    {
        $metadata = $fileData->getMetadata();
        $additionalType = $fileData->getType();

        if ($metadata !== null) {
            // check if metadata is a valid json in all cases
            try {
                $metadataDecoded = json_decode($metadata, false, flags: JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw ApiError::withDetails(Response::HTTP_CONFLICT, 'Bad metadata', $errorPrefix.'-bad-metadata');
            }
        } else {
            $metadataDecoded = null;
        }

        // If additionalType is set the metadata has to match the schema
        if (!$additionalType) {
            return;
        }

        // check if additionaltype is defined
        $bucket = $this->getBucketConfig($fileData->getInternalBucketId());
        $additionalTypes = $bucket->getAdditionalTypes();
        if (!array_key_exists($additionalType, $additionalTypes)) {
            throw ApiError::withDetails(Response::HTTP_CONFLICT, 'Bad type', $errorPrefix.'-bad-type');
        }

        $schemaPath = $additionalTypes[$additionalType];
        $validator = new Validator();
        $validator->validate($metadataDecoded, (object) ['$ref' => 'file://'.realpath($schemaPath)]);
        if (!$validator->isValid()) {
            $messages = [];
            foreach ($validator->getErrors() as $error) {
                $messages[$error['property']] = $error['message'];
            }
            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'metadata does not match specified type',
                $errorPrefix.'-metadata-does-not-match-type', $messages);
        }
    }

    /**
     * Checks if given filedata is valid
     * intended for use before data retrieval using GET.
     *
     * @throws ApiError
     * @throws \Exception
     */
    public function checkFileDataBeforeRetrieval(FileData $fileData, string $errorPrefix): void
    {
        // check if file integrity should be checked and if so, check it
        if ($this->configurationService->doFileIntegrityChecks()) {
            if ($fileData->getFileHash() !== null && $this->getFileHashFromStorage($fileData) !== $fileData->getFileHash()) {
                throw ApiError::withDetails(Response::HTTP_CONFLICT,
                    'sha256 file hash doesnt match! File integrity cannot be guaranteed',
                    $errorPrefix.'-file-hash-mismatch');
            }
            if ($fileData->getMetadataHash() !== null
                && ($fileData->getMetadata() === null
                    || hash('sha256', $fileData->getMetadata()) !== $fileData->getMetadataHash())) {
                throw ApiError::withDetails(Response::HTTP_CONFLICT,
                    'sha256 metadata hash doesnt match! Metadata integrity cannot be guaranteed',
                    $errorPrefix.'-metadata-hash-mismatch');
            }
        }

        $this->validateMetadata($fileData, $errorPrefix);
    }

    public function clearEntityManager(): void
    {
        assert($this->entityManager instanceof EntityManager);
        $this->entityManager->clear();
    }

    /**
     * @throws ApiError
     * @throws \Exception
     */
    private function ensureFileDataIsValid(FileData $fileData, string $errorPrefix): void
    {
        if (Tools::isNullOrEmpty($fileData->getIdentifier())) {
            throw new \RuntimeException('identifier is missing');
        }
        if (Tools::isNullOrEmpty($fileData->getFileName())) {
            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'fileName is missing', $errorPrefix.'-file-name-missing');
        }
        if (Tools::isNullOrEmpty($fileData->getPrefix())) {
            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'prefix is missing', $errorPrefix.'-prefix-missing');
        }
        if (Tools::isNullOrEmpty($fileData->getBucketId())) {
            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'bucket ID is missing', $errorPrefix.'-bucket-id-missing');
        }
        if ($fileData->getFile() !== null) {
            $fileData->setFileSize($fileData->getFile()->getSize());
            $fileData->setMimeType($fileData->getFile()->getMimeType() ?? '');
        }
        $fileData->setInternalBucketId($this->configurationService->getInternalBucketIdByBucketID($fileData->getBucketId()));

        $this->validateMetadata($fileData, $errorPrefix);

        $fileData->setFileHash($this->configurationService->storeFileAndMetadataChecksums() && $fileData->getFile() !== null ?
            \hash_file('sha256', $fileData->getFile()->getPathname()) : null);
        $fileData->setMetadataHash($this->configurationService->storeFileAndMetadataChecksums() && $fileData->getMetadata() !== null ?
            hash('sha256', $fileData->getMetadata()) : null);
    }

    private function getFileStreamInternal(FileData $fileData): StreamInterface
    {
        try {
            return $this->getDatasystemProvider($fileData->getInternalBucketId())
                ->getFileStream($fileData->getInternalBucketId(), $fileData->getIdentifier());
        } catch (\Exception $exception) {
            $this->logger->error(sprintf(
                'failed to get file \'%s\' from datasystem service backend: %s',
                $fileData->getIdentifier(),
                $exception->getMessage()
            ));
            throw ApiError::withDetails(
                Response::HTTP_INTERNAL_SERVER_ERROR,
                'Failed to get file from storage backend',
                'blob:failed-to-get-file-from-datasystem-service'
            );
        }
    }

    private function generateDummyFileWithGivenFilesize(string $filesize): File
    {
        $tempFilePath = tempnam(sys_get_temp_dir(), 'dbp_relay_blob_bundle_blob_service_');
        if ($tempFilePath === false) {
            throw new \RuntimeException('Could not create a unique temporary file path');
        }

        $tempFileResource = fopen($tempFilePath, 'w');
        if ($tempFileResource === false) {
            throw new \RuntimeException('Could not open temporary file for writing');
        }

        // write data in chunks of X bytes
        // this is to not hold everything in memory when generating large files.
        $chunkSize = 1024;
        // generate X letters
        $data = str_repeat('a', $chunkSize);

        $bytesWritten = 0;
        $size = intval($filesize);
        if ($size === 0) {
            throw new \RuntimeException('Could not convert string "filesize" to int');
        }
        while ($bytesWritten < $filesize) {
            $bytesToWrite = min($chunkSize, $size - $bytesWritten);
            $bytesWritten += fwrite($tempFileResource, substr($data, 0, $bytesToWrite));
        }

        return new File($tempFilePath);
    }
}
