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
use Dbp\Relay\BlobBundle\Helper\SignatureUtils;
use Dbp\Relay\CoreBundle\Exception\ApiError;
use Dbp\Relay\CoreBundle\Helpers\Tools;
use Doctrine\ORM\EntityManagerInterface;
use JsonSchema\Validator;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Uid\Uuid;

date_default_timezone_set('UTC');

/**
 * @internal
 */
class BlobService
{
    public const DISABLE_OUTPUT_VALIDATION_OPTION = 'disable_output_validation';
    public const UPDATE_LAST_ACCESS_TIMESTAMP_OPTION = 'update_last_access_timestamp';
    public const ASSERT_BUCKET_ID_EQUALS_OPTION = 'assert_bucket_id_equals';
    public const PREFIX_STARTS_WITH_OPTION = 'prefix_starts_with';
    public const PREFIX_OPTION = 'prefix_equals';
    public const INCLUDE_DELETE_AT_OPTION = 'include_delete_at';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ConfigurationService $configurationService,
        private DatasystemProviderService $datasystemService,
        private readonly EventDispatcherInterface $eventDispatcher)
    {
    }

    public function checkConnection(): void
    {
        $this->em->getConnection()->getNativeConnection();
    }

    /**
     * @throws \Exception
     */
    public function addFile(FileData $fileData): FileData
    {
        if ($fileData->getFile() === null) {
            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'file is missing', 'blob:create-file-data-file-missing');
        }

        $fileData->setIdentifier(Uuid::v7()->toRfc4122());

        $now = BlobUtils::now();
        $fileData->setDateCreated($now);
        $fileData->setLastAccess($now);
        $fileData->setDateModified($now);

        if ($fileData->getRetentionDuration() !== null) {
            $fileData->setDeleteAt($fileData->getDateCreated()->add(
                new \DateInterval($fileData->getRetentionDuration())));
        }

        $errorPrefix = 'blob:create-file-data';
        $this->ensureFileDataIsValid($fileData, $errorPrefix);

        // write all relevant data to tables
        $this->writeToTablesAndSaveFileData($fileData, $fileData->getFileSize(), $errorPrefix);

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
        $fileData->setLastAccess($now);
        $fileData->setDateModified($now);

        $errorPrefix = 'blob:patch-file-data';
        $this->ensureFileDataIsValid($fileData, $errorPrefix);

        if ($fileData->getFile() instanceof File) {
            $this->writeToTablesAndSaveFileData($fileData, $fileData->getFileSize() - $previousFileData->getFileSize(), $errorPrefix);
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
    public function removeFile(string $identifier, ?FileData $fileData = null): void
    {
        $fileData ??= $this->getFileData($identifier);
        $this->writeToTablesAndRemoveFileData($fileData, -$fileData->getFileSize());

        $deleteSuccessEvent = new DeleteFileDataByDeleteSuccessEvent($fileData);
        $this->eventDispatcher->dispatch($deleteSuccessEvent);
    }

    /**
     * @throws \Exception
     */
    public function getFile(string $identifier, array $options = []): FileData
    {
        $fileData = $this->getFileData($identifier);

        if (($bucketIdToMatch = $options[self::ASSERT_BUCKET_ID_EQUALS_OPTION] ?? null) !== null) {
            if ($fileData->getBucketID() !== $bucketIdToMatch) {
                throw new ApiError(Response::HTTP_FORBIDDEN);
            }
        }

        if ($fileData->getDeleteAt() !== null && ($fileData->getDeleteAt() < BlobUtils::now() || !($options[self::INCLUDE_DELETE_AT_OPTION] ?? false))) {
            throw ApiError::withDetails(Response::HTTP_NOT_FOUND, 'FileData was not found!', 'blob:file-data-not-found');
        }

        $bucket = $this->getBucketConfig($fileData);
        if (!($options[self::DISABLE_OUTPUT_VALIDATION_OPTION] ?? false) && $bucket->getOutputValidation()) {
            $this->checkFileDataBeforeRetrieval($fileData, 'blob:get-file-data');
        }

        if ($options[self::UPDATE_LAST_ACCESS_TIMESTAMP_OPTION] ?? true) {
            $fileData->setLastAccess(BlobUtils::now());
            $this->saveFileData($fileData);
        }

        return $fileData;
    }

    public function getFiles(string $bucketIdentifier, array $options = [], int $currentPageNumber = 1, int $maxNumItemsPerPage = 30): array
    {
        $errorPrefix = 'blob:get-file-data-collection';

        // TODO: make the upper limit configurable
        // hard limit page size
        if ($maxNumItemsPerPage > 1000) {
            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'Requested too many items per page', $errorPrefix.'-too-many-items-per-page');
        }

        $internalBucketId = $this->getInternalBucketIdByBucketID($bucketIdentifier);
        $prefix = $options[self::PREFIX_OPTION] ?? '';
        $prefixStartsWith = $options[self::PREFIX_STARTS_WITH_OPTION] ?? false;
        $includeDeleteAt = $options[self::INCLUDE_DELETE_AT_OPTION] ?? false;

        $fileDataCollection = $this->getFileDataCollection($internalBucketId, $prefix, $currentPageNumber, $maxNumItemsPerPage, $prefixStartsWith, $includeDeleteAt);

        foreach ($fileDataCollection as $fileData) {
            assert($fileData instanceof FileData);
            $fileData->setBucketId($bucketIdentifier);
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
        if ($request->headers->get('Content-Length') && intval($request->headers->get('Content-Length')) !== 0
            && intval($request->headers->get('Content-Length')) > BlobUtils::convertFileSizeStringToBytes(ini_get('post_max_size'))) {
            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST,
                'Given file is too large', $errorPrefix.'-file-too-big');
        }

        /* get url params */
        $bucketID = $request->query->get('bucketIdentifier');
        $prefix = $request->query->get('prefix');
        $notifyEmail = $request->query->get('notifyEmail');
        $deleteIn = $request->query->get('deleteIn');
        $type = $request->query->get('type');

        /* get params from body */
        $metadata = $request->request->get('metadata');
        $fileName = $request->request->get('fileName');
        $fileHash = $request->request->get('fileHash');
        $metadataHash = $request->request->get('metadataHash');

        /* get uploaded file */
        $uploadedFile = $request->files->get('file');

        /* check types of params */
        assert(is_string($bucketID ?? ''));
        assert(is_string($prefix ?? ''));
        assert(is_string($fileName ?? ''));
        assert(is_string($fileHash ?? ''));
        assert(is_string($notifyEmail ?? ''));
        assert(is_string($deleteIn ?? ''));
        assert(is_string($type ?? ''));
        assert(is_string($metadata ?? ''));
        assert($uploadedFile === null || $uploadedFile instanceof File);

        /* url decode according to RFC 3986 */
        $bucketID = $bucketID ? rawurldecode($bucketID) : null;
        $prefix = $prefix ? rawurldecode($prefix) : null;
        $notifyEmail = $notifyEmail ? rawurldecode($notifyEmail) : null;
        $deleteIn = $deleteIn ? rawurldecode($deleteIn) : null;
        $type = $type ? rawurldecode($type) : null;

        $fileData->setBucketId($bucketID);

        if ($uploadedFile !== null) {
            if ($uploadedFile instanceof UploadedFile && $uploadedFile->getError() !== UPLOAD_ERR_OK) {
                throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, $uploadedFile->getErrorMessage(),
                    $errorPrefix.'-upload-error');
            }
            if ($uploadedFile->getSize() === 0) {
                throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'Empty files cannot be added!',
                    $errorPrefix.'-empty-files-not-allowed');
            }
            if ($fileHash !== null && $fileHash !== hash('sha256', $uploadedFile->getContent())) {
                throw ApiError::withDetails(Response::HTTP_FORBIDDEN, 'File hash change forbidden',
                    $errorPrefix.'-file-hash-change-forbidden');
            }

            $fileData->setFile($uploadedFile);
        }

        if ($metadataHash !== null) {
            if ($metadataHash !== hash('sha256', $metadata)) {
                throw ApiError::withDetails(Response::HTTP_FORBIDDEN, 'Metadata hash change forbidden',
                    $errorPrefix.'-metadata-hash-change-forbidden');
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

    public function getInternalBucketIdByBucketID(string $bucketID): ?string
    {
        return $this->configurationService->getInternalBucketIdByBucketID($bucketID);
    }

    public function doFileIntegrityChecks(): bool
    {
        return $this->configurationService->doFileIntegrityChecks();
    }

    /**
     * Sets the bucket ID of the given fileData and returns the bucket config.
     *
     * @param FileData $fileData fileData which is missing the bucket ID
     */
    public function getBucketConfig(FileData $fileData): BucketConfig
    {
        return $this->configurationService->getBucketByID($fileData->getBucketId());
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
            $this->em->persist($fileData);
            $this->em->flush();
        } catch (\Exception $e) {
            throw ApiError::withDetails(Response::HTTP_INTERNAL_SERVER_ERROR, 'File could not be saved!',
                'blob:file-not-saved', ['message' => $e->getMessage()]);
        }
    }

    /**
     * Used to get a bucket from the db using doctrine.
     *
     * @param string $bucketId internal bucket ID of the bucket
     *
     * @throws \Exception
     */
    public function getBucketSizeByInternalIdFromDatabase(string $bucketId): BucketSize
    {
        $bucket = $this->em->getRepository(BucketSize::class)->find($bucketId);

        if (!$bucket) {
            $newBucket = new BucketSize();
            $newBucket->setIdentifier($bucketId);
            $newBucket->setCurrentBucketSize(0);
            $this->em->persist($newBucket);
            $this->em->flush();
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
            $this->em->persist($bucket);
            $this->em->flush();
        } catch (\Exception $e) {
            throw ApiError::withDetails(Response::HTTP_INTERNAL_SERVER_ERROR, 'Bucket data could not be saved!', 'blob:file-not-saved', ['message' => $e->getMessage()]);
        }
    }

    /**
     * Saves the file using the connector.
     *
     * @param FileData $fileData fileData that carries the file which should be saved
     *
     * @throws \Exception
     */
    public function saveFile(FileData $fileData): ?FileData
    {
        try {
            // save the file using the connector
            $this->getDatasystemProvider($fileData)->saveFile($fileData->getInternalBucketID(), $fileData->getIdentifier(), $fileData->getFile());

            return $fileData;
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Get HTTP link to binary content.
     *
     * @param FileData $fileData fileData for which a link should be provided
     *
     * @throws \Exception
     */
    public function getDownloadLink(string $baseurl, FileData $fileData): string
    {
        return $baseurl.SignatureUtils::getSignedUrl('/blob/files/'.$fileData->getIdentifier().'/download',
            $this->getBucketConfig($fileData)->getKey(), $fileData->getBucketID(), Request::METHOD_GET);
    }

    /**
     * @throws ApiError
     */
    public function getContent(FileData $fileData): string
    {
        $response = $this->getDatasystemProvider($fileData)->getBinaryResponse($fileData->getInternalBucketID(), $fileData->getIdentifier());

        try {
            if (ob_start() !== true) {
                throw new \RuntimeException();
            }
            try {
                $response->sendContent();
                $content = ob_get_contents();
                if ($content === false) {
                    throw new \RuntimeException(); // @phpstan-ignore-line
                }
            } finally {
                if (ob_end_clean() === false) {
                    throw new \RuntimeException(); // @phpstan-ignore-line
                }
            }
        } catch (\RuntimeException) {
            throw ApiError::withDetails(Response::HTTP_NOT_FOUND, 'File went missing', 'blob:file-not-found');
        }

        return $content;
    }

    /**
     * Get file content as base64 contentUrl.
     *
     * @param FileData $fileData fileData for which the base64 encoded file should be provided
     *
     * @throws ApiError
     */
    public function getContentUrl(FileData $fileData): string
    {
        $mimeType = $fileData->getMimeType();
        $content = $this->getContent($fileData);

        return 'data:'.$mimeType.';base64,'.base64_encode($content);
    }

    /**
     * Get file as binary response.
     *
     * @throws \Exception
     */
    public function getBinaryResponse(string $fileIdentifier, array $options = []): Response
    {
        $fileData = $this->getFile($fileIdentifier, $options);

        // get binary response of file with connector
        $response = $this->getDatasystemProvider($fileData)->getBinaryResponse($fileData->getInternalBucketID(), $fileData->getIdentifier());
        $response->headers->set('Content-Type', $fileData->getMimeType());

        $dispositionHeader = $response->headers->makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $fileData->getFileName());
        $response->headers->set('Content-Disposition', $dispositionHeader);

        return $response;
    }

    /**
     * Get fileData for file with given identifier.
     */
    public function getFileData(string $identifier): FileData
    {
        if (!Uuid::isValid($identifier)) {
            throw ApiError::withDetails(Response::HTTP_NOT_FOUND, 'FileData was not found!', 'blob:file-data-not-found');
        }

        /** @var ?FileData $fileData */
        $fileData = $this->em
            ->getRepository(FileData::class)
            ->find($identifier);

        if (!$fileData) {
            throw ApiError::withDetails(Response::HTTP_NOT_FOUND, 'FileData was not found!', 'blob:file-data-not-found');
        }

        $fileData->setBucketId($this->configurationService->getBucketByInternalID($fileData->getInternalBucketID())->getBucketID());

        return $fileData;
    }

    /**
     * Get all the fileDatas of a given bucketID.
     */
    public function getFileDataByBucketID(string $bucketID): array
    {
        return $this->em
            ->getRepository(FileData::class)
            ->findBy(['internalBucketId' => $bucketID]);
    }

    public function getFileDataCollection(string $bucketID, string $prefix, int $currentPageNumber, int $maxNumItemsPerPage, bool $startsWith, bool $includeDeleteAt)
    {
        $query = $this->em
            ->getRepository(FileData::class)
            ->createQueryBuilder('f');
        $query = $query
            ->where($query->expr()->eq('f.internalBucketId', ':bucketID'));

        if ($startsWith) {
            $query = $query->andWhere($query->expr()->like('f.prefix', ':prefix'))
                ->setParameter('prefix', $prefix.'%');
        } else {
            $query = $query->andWhere($query->expr()->eq('f.prefix', ':prefix'))
                ->setParameter('prefix', $prefix);
        }

        if ($includeDeleteAt) {
            $query = $query->andWhere($query->expr()->orX($query->expr()->gt('f.deleteAt', ':now'), $query->expr()->isNull('f.deleteAt')))
                ->setParameter('now', BlobUtils::now());
        } else {
            $query = $query->andWhere($query->expr()->isNull('f.deleteAt'));
        }

        $query = $query
            ->orderBy('f.dateCreated', 'ASC')
            ->setParameter('bucketID', $bucketID)
            ->setFirstResult($maxNumItemsPerPage * ($currentPageNumber - 1))
            ->setMaxResults($maxNumItemsPerPage);

        return $query->getQuery()->getResult();
    }

    public function getDatasystemProvider(FileData $fileData): DatasystemProviderServiceInterface
    {
        return $this->datasystemService->getServiceByBucket($this->getBucketConfig($fileData));
    }

    /**
     * Remove a given fileData from the entity manager.
     */
    private function removeFileData(FileData $fileData): void
    {
        $this->em->remove($fileData);
        $this->em->flush();

        $this->getDatasystemProvider($fileData)->removeFile($fileData->getInternalBucketID(), $fileData->getIdentifier());
    }

    /**
     * Cleans the table from resources that exceeded their deleteAt date.
     *
     * @throws \Exception
     */
    public function cleanUp(): void
    {
        // get all invalid filedatas
        $now = BlobUtils::now();

        $maxNumItemsPerPage = 10000;
        $pagesize = 10000;

        while ($pagesize === $maxNumItemsPerPage) {
            $invalidFileDataQuery = $this->em
                ->getRepository(FileData::class)
                ->createQueryBuilder('f')
                ->where('f.deleteAt IS NOT NULL')
                ->AndWhere('f.deleteAt < :now')
                ->setParameter('now', $now)
                ->setMaxResults($maxNumItemsPerPage)
                ->getQuery();

            $invalidFileDatas = $invalidFileDataQuery->getResult();
            $pagesize = count($invalidFileDatas);

            // Remove all links, files and reference
            foreach ($invalidFileDatas as $invalidFileData) {
                $this->removeFileData($invalidFileData);
            }
        }
    }

    /**
     * @throws \Exception
     */
    public function writeToTablesAndSaveFileData(FileData $fileData, int $bucketSizeDeltaByte, string $errorPrefix): void
    {
        /* Check quota */
        $bucketQuotaByte = $this->getBucketConfig($fileData)->getQuota() * 1024 * 1024; // Convert mb to Byte
        $bucketSize = $this->getBucketSizeByInternalIdFromDatabase($fileData->getInternalBucketID());
        $newBucketSizeByte = max($bucketSize->getCurrentBucketSize() + $bucketSizeDeltaByte, 0);
        if ($newBucketSizeByte > $bucketQuotaByte) {
            throw ApiError::withDetails(Response::HTTP_INSUFFICIENT_STORAGE, 'Bucket quota is reached',
                $errorPrefix.'-bucket-quota-reached');
        }
        $bucketSize->setCurrentBucketSize($newBucketSizeByte);

        // Return correct data for service and save the data
        $fileData = $this->saveFile($fileData);
        if (!$fileData) {
            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'data upload failed',
                $errorPrefix.'-data-upload-failed');
        }

        // try to update bucket size
        $this->em->getConnection()->beginTransaction();
        try {
            $this->saveBucketSize($bucketSize);
            $this->saveFileData($fileData);

            $this->em->getConnection()->commit();
        } catch (\Exception $e) {
            $this->em->getConnection()->rollBack();
            $this->removeFileData($fileData);
            throw ApiError::withDetails(Response::HTTP_INTERNAL_SERVER_ERROR, 'Error while saving the file data',
                $errorPrefix.'-save-file-failed');
        }
    }

    /**
     * @throws \Exception
     */
    public function writeToTablesAndRemoveFileData(FileData $fileData, int $bucketSizeDeltaByte): void
    {
        $bucketSize = $this->getBucketSizeByInternalIdFromDatabase($fileData->getInternalBucketID());
        $newBucketSizeByte = max($bucketSize->getCurrentBucketSize() + $bucketSizeDeltaByte, 0);
        $bucketSize->setCurrentBucketSize($newBucketSizeByte);

        // try to update bucket size
        $this->em->getConnection()->beginTransaction();
        try {
            $this->saveBucketSize($bucketSize);
            $this->removeFileData($fileData);

            $this->em->getConnection()->commit();
        } catch (\Exception $e) {
            $this->em->getConnection()->rollBack();
            throw ApiError::withDetails(Response::HTTP_INTERNAL_SERVER_ERROR, 'Error while removing the file data',
                'blob:remove-file-data-save-file-failed');
        }
    }

    public function validateMetadata(FileData $fileData, string $errorPrefix)
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
        $bucket = $this->getBucketConfig($fileData);
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
            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'metadata does not match specified type', $errorPrefix.'-metadata-does-not-match-type', $messages);
        }
    }

    /**
     * Checks if given filedata is valid
     * intended for use before data retrieval using GET.
     */
    public function checkFileDataBeforeRetrieval(FileData $fileData, string $errorPrefix): void
    {
        // check if file integrity should be checked and if so check it
        if ($this->doFileIntegrityChecks() && $fileData->getFileHash() !== null && hash('sha256', $this->getContent($fileData)) !== $fileData->getFileHash()) {
            throw ApiError::withDetails(Response::HTTP_CONFLICT, 'sha256 file hash doesnt match! File integrity cannot be guaranteed', $errorPrefix.'-file-hash-mismatch');
        }
        if ($this->doFileIntegrityChecks() && $fileData->getMetadataHash() !== null
            && ($fileData->getMetadata() === null || hash('sha256', $fileData->getMetadata()) !== $fileData->getMetadataHash())) {
            throw ApiError::withDetails(Response::HTTP_CONFLICT, 'sha256 metadata hash doesnt match! Metadata integrity cannot be guaranteed', $errorPrefix.'-metadata-hash-mismatch');
        }

        $this->validateMetadata($fileData, $errorPrefix);
    }

    /**
     * @throws ApiError|\DateMalformedIntervalStringException
     * @throws \DateMalformedStringException
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
        $fileData->setInternalBucketID($this->configurationService->getInternalBucketIdByBucketID($fileData->getBucketId()));

        if ($fileData->getFile() !== null) {
            $fileData->setMimeType($fileData->getFile()->getMimeType() ?? '');
            $fileData->setFilesize($fileData->getFile()->getSize());
        }

        $this->validateMetadata($fileData, $errorPrefix);

        $fileData->setFileHash($this->configurationService->doFileIntegrityChecks() && $fileData->getFile() !== null ?
            hash('sha256', $fileData->getFile()->getContent()) : null);
        $fileData->setMetadataHash($this->configurationService->doFileIntegrityChecks() && $fileData->getMetadata() !== null ?
            hash('sha256', $fileData->getMetadata()) : null);
    }
}
