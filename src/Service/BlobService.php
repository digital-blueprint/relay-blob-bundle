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
use Symfony\Component\Uid\Uuid;

date_default_timezone_set('UTC');

/**
 * @internal
 */
class BlobService
{
    public const INCLUDE_FILE_CONTENTS_OPTION = 'include_file_contents';
    public const DISABLE_OUTPUT_VALIDATION_OPTION = 'disable_output_validation';
    public const UPDATE_LAST_ACCESS_TIMESTAMP_OPTION = 'update_last_access_timestamp';
    public const ASSERT_BUCKET_ID_EQUALS_OPTION = 'assert_bucket_id_equals';
    public const PREFIX_STARTS_WITH_OPTION = 'prefix_starts_with';
    public const PREFIX_OPTION = 'prefix_equals';
    public const INCLUDE_DELETE_AT_OPTION = 'include_delete_at';
    public const BASE_URL_OPTION = 'base_url';

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
        $this->ensureBucketId($fileData);
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

        $this->ensureBucketId($fileData);
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

        $this->ensureBucketId($fileData);
        $deleteSuccessEvent = new DeleteFileDataByDeleteSuccessEvent($fileData);
        $this->eventDispatcher->dispatch($deleteSuccessEvent);
    }

    /**
     * @throws \Exception
     */
    public function getFile(string $identifier, array $options = []): FileData
    {
        $fileData = $this->getFileData($identifier);

        $bucket = $this->ensureBucketId($fileData);
        if (($bucketIdToMatch = $options[self::ASSERT_BUCKET_ID_EQUALS_OPTION] ?? null) !== null) {
            if ($bucket->getBucketID() !== $bucketIdToMatch) {
                throw new ApiError(Response::HTTP_FORBIDDEN);
            }
        }

        if ($fileData->getDeleteAt() !== null && $fileData->getDeleteAt() < BlobUtils::now()) {
            throw ApiError::withDetails(Response::HTTP_NOT_FOUND, 'FileData was not found!', 'blob:file-data-not-found');
        }

        $bucket = $this->ensureBucketId($fileData);
        if (!($options[self::DISABLE_OUTPUT_VALIDATION_OPTION] ?? false) && $bucket->getOutputValidation()) {
            $this->checkFileDataBeforeRetrieval($fileData, 'blob:get-file-data');
        }

        if ($options[self::INCLUDE_FILE_CONTENTS_OPTION] ?? false) {
            $fileData->setContentUrl($this->getContentUrl($fileData));
        } else {
            $fileData = $this->getLink($options[self::BASE_URL_OPTION] ?? '', $fileData);
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
        $internalBucketId = $this->getInternalBucketIdByBucketID($bucketIdentifier);

        // TODO: make the upper limit configurable
        // hard limit page size
        if ($maxNumItemsPerPage > 10000) {
            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'Requested too many items per page', $errorPrefix.'-too-many-items-per-page');
        }

        $prefix = $options[self::PREFIX_OPTION] ?? '';
        $prefixStartsWith = $options[self::PREFIX_STARTS_WITH_OPTION] ?? false;
        $includeDeleteAt = $options[self::INCLUDE_DELETE_AT_OPTION] ?? false;
        $includeData = $options[self::INCLUDE_FILE_CONTENTS_OPTION] ?? false;
        $baseUrl = $options[self::BASE_URL_OPTION] ?? '';

        $fileDatas = $this->getFileDataCollection($internalBucketId, $prefix, $currentPageNumber, $maxNumItemsPerPage, $prefixStartsWith, $includeDeleteAt);

        // create sharelinks
        $validFileDatas = [];
        foreach ($fileDatas as $fileData) {
            try {
                assert($fileData instanceof FileData);

                $this->ensureBucketId($fileData);
                $fileData = $this->getLink($baseUrl, $fileData);
                $fileData->setContentUrl($this->generateGETLink($baseUrl, $fileData, $includeData ? '1' : ''));

                $validFileDatas[] = $fileData;
            } catch (\Exception $e) {
                // skip file not found
                // TODO how to handle this correctly? This should never happen in the first place
                if ($e->getCode() === 404) {
                    continue;
                }
            }
        }

        return $validFileDatas;
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
        $retentionDuration = $request->query->get('retentionDuration');
        $type = $request->query->get('type');
        $deleteAt = $request->query->get('deleteAt');

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
        assert(is_string($retentionDuration ?? ''));
        assert(is_string($type ?? ''));
        assert(is_string($metadata ?? ''));
        assert($uploadedFile === null || $uploadedFile instanceof File);

        /* url decode according to RFC 3986 */
        $bucketID = $bucketID ? rawurldecode($bucketID) : null;
        $prefix = $prefix ? rawurldecode($prefix) : null;
        $notifyEmail = $notifyEmail ? rawurldecode($notifyEmail) : null;
        $retentionDuration = $retentionDuration ? rawurldecode($retentionDuration) : null;
        $type = $type ? rawurldecode($type) : null;

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
        if ($retentionDuration !== null) {
            $fileData->setRetentionDuration($retentionDuration);
        }
        if ($deleteAt !== null) {
            // check if date can be created
            $date = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $deleteAt);
            if ($date === false) {
                // RFC3339_EXTENDED is broken in PHP
                $date = \DateTimeImmutable::createFromFormat("Y-m-d\TH:i:s.uP", $deleteAt);
            }
            if ($date === false) {
                throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'Given deleteAt is in an invalid format!', 'blob:patch-file-data-delete-at-bad-format');
            }
            $fileData->setDeleteAt($date);
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

        $fileData->setInternalBucketID($this->configurationService->getInternalBucketIdByBucketID($bucketID));
        $this->ensureBucketId($fileData);

        $this->getLink($request->getSchemeAndHttpHost(), $fileData);

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
    public function ensureBucketId(FileData $fileData): BucketConfig
    {
        $bucketConfig = $this->configurationService->getBucketByInternalID($fileData->getInternalBucketID());
        if (!$bucketConfig) {
            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'BucketID is not configured',
                'blob:create-file-data-not-configured-bucket-id');
        }

        $bucketId = $fileData->getBucketId();
        if (!$bucketId) {
            $fileData->setBucketId($bucketConfig->getBucketID());
        }

        return $bucketConfig;
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
            $this->getDatasystemProvider($fileData)->saveFile($fileData);

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
    public function getLink(string $baseurl, FileData $fileData): FileData
    {
        $bucket = $this->ensureBucketId($fileData);

        // get time now
        $now = BlobUtils::now();

        // generate checksum and encode it in payload
        $payload = [
            'cs' => $this->generateChecksumFromFileData($fileData, 'GET', $now),
        ];

        // get HTTP link with connector for fileData
        $fileData->setContentUrl($baseurl.$this->generateSignedDownloadUrl($fileData, $now, SignatureUtils::create($bucket->getKey(), $payload)));

        return $fileData;
    }

    /**
     * Get file content as base64 contentUrl.
     *
     * @param FileData $fileData fileData for which the base64 encoded file should be provided
     */
    public function getContentUrl(FileData $fileData): string
    {
        return $this->getDatasystemProvider($fileData)->getContentUrl($fileData);
    }

    /**
     * Get file as binary response.
     *
     * @throws \Exception
     */
    public function getBinaryResponse(string $fileIdentifier, array $options = []): Response
    {
        $options[self::INCLUDE_FILE_CONTENTS_OPTION] = false;
        $fileData = $this->getFile($fileIdentifier, $options);

        // get binary response of file with connector
        return $this->getDatasystemProvider($fileData)->getBinaryResponse($fileData);
    }

    /**
     * Generate HTTP link to blob resource.
     *
     * @throws \JsonException
     */
    public function generateGETLink(string $baseUrl, FileData $fileData, string $includeData = ''): string
    {
        $bucket = $this->ensureBucketId($fileData);

        // get time now
        $now = BlobUtils::now();

        // generate checksum and encode it in payload
        $payload = [
            'cs' => $this->generateChecksumFromFileData($fileData, 'GET', $now, $includeData),
        ];

        $url = '';
        if (!$includeData) {
            // set content url
            $filePath = $this->generateSignedContentUrl($fileData, 'GET', $now, $includeData, SignatureUtils::create($bucket->getKey(), $payload));
            $url = $baseUrl.'/'.substr($filePath, 1);
        } else {
            try {
                $url = $this->getContentUrl($fileData);
            } catch (\Exception $e) {
                throw ApiError::withDetails(Response::HTTP_NOT_FOUND, 'File went missing', 'blob:file-not-found');
            }
        }

        // build and return HTTP path
        return $url;
    }

    /**
     * Generate signed content url for get requests by identifier
     * This is useful for generating the HTTP contentUrls for every fileData.
     *
     * @param FileData           $fileData    fileData for which the HTTP url should be generated
     * @param string             $urlMethod   method which is used
     * @param \DateTimeImmutable $now         timestamp of now which is used as creationTime
     * @param string             $includeData specifies whether includeData should be =1 or left out
     * @param string             $sig         signature with checksum that is used
     */
    public function generateSignedContentUrl(FileData $fileData, string $urlMethod, \DateTimeImmutable $now, string $includeData, string $sig): string
    {
        if ($includeData) {
            return '/blob/files/'.$fileData->getIdentifier().'?bucketIdentifier='.$fileData->getInternalBucketID().'&creationTime='.rawurlencode($now->format('c')).'&includeData=1&method='.$urlMethod.'&sig='.$sig;
        } else {
            return '/blob/files/'.$fileData->getIdentifier().'?bucketIdentifier='.$fileData->getInternalBucketID().'&creationTime='.rawurlencode($now->format('c')).'&method='.$urlMethod.'&sig='.$sig;
        }
    }

    /**
     * Generate signed content url for get requests by identifier
     * This is useful for generating the HTTP contentUrls for every fileData.
     *
     * @param FileData           $fileData fileData for which the HTTP url should be generated
     * @param \DateTimeImmutable $now      timestamp of now which is used as creationTime
     * @param string             $sig      signature with checksum that is used
     */
    public function generateSignedDownloadUrl(FileData $fileData, \DateTimeImmutable $now, string $sig): string
    {
        return '/blob/files/'.$fileData->getIdentifier().'/download?bucketIdentifier='.$fileData->getInternalBucketID().'&creationTime='.rawurlencode($now->format('c')).'&method=GET&sig='.$sig;
    }

    /**
     * Generate the sha256 hash from a HTTP url.
     *
     * @param FileData           $fileData    fileData for which the HTTP url should be generated
     * @param string             $urlMethod   method used in the request
     * @param \DateTimeImmutable $now         timestamp of now which is used as creationTime
     * @param string             $includeData specified whether includeData should be =1 or left out
     */
    public function generateChecksumFromFileData(FileData $fileData, string $urlMethod, \DateTimeImmutable $now, string $includeData = ''): ?string
    {
        // check whether includeData should be in url or not
        if (!$includeData) {
            // create url to hash
            $contentUrl = '/blob/files/'.$fileData->getIdentifier().'?bucketIdentifier='.$fileData->getInternalBucketID().'&creationTime='.rawurlencode($now->format('c')).'&method='.$urlMethod;
        } else {
            // create url to hash
            $contentUrl = '/blob/files/'.$fileData->getIdentifier().'?bucketIdentifier='.$fileData->getInternalBucketID().'&creationTime='.rawurlencode($now->format('c')).'&includeData=1&method='.$urlMethod;
        }

        // create sha256 hash
        $cs = hash('sha256', $contentUrl);

        return $cs;
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

        return $fileData;
    }

    /**
     * Get all the fileDatas of a given bucketID.
     */
    public function getFileDataByBucketID(string $bucketID): array
    {
        $fileDatas = $this->em
            ->getRepository(FileData::class)
            ->findBy(['internalBucketId' => $bucketID]);

        if (!$fileDatas) {
            throw ApiError::withDetails(Response::HTTP_NOT_FOUND, 'FileDatas was not found!', 'blob:file-data-not-found');
        }

        return $fileDatas;
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
        return $this->datasystemService->getServiceByBucket($this->ensureBucketId($fileData));
    }

    /**
     * Remove a given fileData from the entity manager.
     */
    private function removeFileData(FileData $fileData): void
    {
        $this->em->remove($fileData);
        $this->em->flush();

        $this->getDatasystemProvider($fileData)->removeFile($fileData);
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
        $bucketQuotaByte = $this->ensureBucketId($fileData)->getQuota() * 1024 * 1024; // Convert mb to Byte
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
        $additionalMetadata = $fileData->getMetadata();
        $additionalType = $fileData->getType();

        if ($additionalMetadata) {
            // check if metadata is a valid json in all cases
            try {
                $metadataDecoded = json_decode($additionalMetadata, false, flags: JSON_THROW_ON_ERROR);
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
        $bucket = $this->ensureBucketId($fileData);
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
        $content = base64_decode(explode(',', $this->getContentUrl($fileData))[1], true);

        if ($content === false) {
            throw ApiError::withDetails(Response::HTTP_CONFLICT, 'file data cannot be decoded', $errorPrefix.'-decode-fail');
        }
        // check if file integrity should be checked and if so check it
        if ($this->doFileIntegrityChecks() && $fileData->getFileHash() !== null && hash('sha256', $content) !== $fileData->getFileHash()) {
            throw ApiError::withDetails(Response::HTTP_CONFLICT, 'sha256 file hash doesnt match! File integrity cannot be guaranteed', $errorPrefix.'-file-hash-mismatch');
        }
        if ($this->doFileIntegrityChecks() && $fileData->getMetadataHash() !== null && hash('sha256', $fileData->getMetadata()) !== $fileData->getMetadataHash()) {
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
        if (Tools::isNullOrEmpty($fileData->getInternalBucketID())) {
            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'internal bucket ID is missing', $errorPrefix.'-internal-bucket-id-missing');
        }

        if ($fileData->getFile() !== null) {
            $fileData->setMimeType($fileData->getFile()->getMimeType() ?? '');
            $fileData->setFilesize($fileData->getFile()->getSize());
        }

        $this->validateMetadata($fileData, $errorPrefix);

        if ($this->configurationService->doFileIntegrityChecks()) {
            if ($fileData->getFile() !== null) {
                $fileData->setFileHash(hash('sha256', $fileData->getFile()->getContent()));
            }
            $fileData->setMetadataHash(hash('sha256', $fileData->getMetadata()));
        } else {
            $fileData->setFileHash(null);
            $fileData->setMetadataHash(null);
        }
    }
}
