<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Service;

use Dbp\Relay\BlobBundle\Entity\Bucket;
use Dbp\Relay\BlobBundle\Entity\FileData;
use Dbp\Relay\BlobBundle\Event\AddFileDataByPostSuccessEvent;
use Dbp\Relay\BlobBundle\Event\ChangeFileDataByPatchSuccessEvent;
use Dbp\Relay\BlobBundle\Event\DeleteFileDataByDeleteSuccessEvent;
use Dbp\Relay\BlobBundle\Helper\BlobUtils;
use Dbp\Relay\BlobBundle\Helper\DenyAccessUnlessCheckSignature;
use Dbp\Relay\CoreBundle\Exception\ApiError;
use Dbp\Relay\CoreBundle\Helpers\Tools;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\NonUniqueResultException;
use JsonSchema\Constraints\Factory;
use JsonSchema\SchemaStorage;
use JsonSchema\Validator;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Email;
use Symfony\Component\Uid\Uuid;
use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;
use Twig\Loader\FilesystemLoader;

date_default_timezone_set('UTC');

/**
 * @internal
 */
class BlobService
{
    public const INCLUDE_FILE_CONTENTS_OPTION = 'include_file_contents';
    public const DISABLE_OUTPUT_VALIDATION_OPTION = 'disable_output_validation';
    public const UPDATE_LAST_ACCESS_TIMESTAMP_OPTION = 'update_last_access_timestamp';
    public const PREFIX_STARTS_WITH_OPTION = 'prefix_starts_with';
    public const PREFIX_OPTION = 'prefix_equals';
    public const INCLUDE_DELETE_AT_OPTION = 'include_delete_at';
    public const BASE_URL_OPTION = 'base_url';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ConfigurationService $configurationService,
        private DatasystemProviderService $datasystemService,
        private readonly KernelInterface $kernel,
        private readonly EventDispatcherInterface $eventDispatcher)
    {
    }

    public function setDatasystemService(DatasystemProviderService $datasystemService): void
    {
        $this->datasystemService = $datasystemService;
    }

    public function getConfigurationService(): ConfigurationService
    {
        return $this->configurationService;
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
        $this->ensureBucket($fileData);
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

        if ($fileData->getFile() instanceof UploadedFile) {
            $this->writeToTablesAndSaveFileData($fileData, $fileData->getFileSize() - $previousFileData->getFileSize(), $errorPrefix);
        } else {
            $this->saveFileData($fileData);
        }

        $this->ensureBucket($fileData);
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

        $this->ensureBucket($fileData);
        $deleteSuccessEvent = new DeleteFileDataByDeleteSuccessEvent($fileData);
        $this->eventDispatcher->dispatch($deleteSuccessEvent);
    }

    /**
     * @throws \Exception
     */
    public function getFile(string $identifier, array $options = []): ?FileData
    {
        $fileData = $this->getFileData($identifier);

        if ($fileData->getDeleteAt() !== null && $fileData->getDeleteAt() < BlobUtils::now()) {
            throw ApiError::withDetails(Response::HTTP_NOT_FOUND, 'FileData was not found!', 'blob:file-data-not-found');
        }

        $bucket = $this->ensureBucket($fileData);
        if (!($options[self::DISABLE_OUTPUT_VALIDATION_OPTION] ?? false) && $bucket->getOutputValidation()) {
            $this->checkFileDataBeforeRetrieval($fileData, $bucket, 'blob:get-file-data');
        }

        if ($options[self::INCLUDE_FILE_CONTENTS_OPTION] ?? false) {
            $fileData = $this->getBase64Data($fileData);
        } else {
            $fileData = $this->getLink($fileData);
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

        // get file data of bucket for current page, and decide whether prefix should be used as 'startsWith' or not
        if ($prefixStartsWith && $includeDeleteAt) {
            $fileDatas = $this->getFileDataByBucketIDAndStartsWithPrefixAndIncludeDeleteAtWithPagination($internalBucketId, $prefix, $currentPageNumber, $maxNumItemsPerPage);
        } elseif ($prefixStartsWith) {
            $fileDatas = $this->getFileDataByBucketIDAndStartsWithPrefixWithPagination($internalBucketId, $prefix, $currentPageNumber, $maxNumItemsPerPage);
        } elseif ($includeDeleteAt) {
            $fileDatas = $this->getFileDataByBucketIDAndPrefixAndIncludeDeleteAtWithPagination($internalBucketId, $prefix, $currentPageNumber, $maxNumItemsPerPage);
        } else {
            $fileDatas = $this->getFileDataByBucketIDAndPrefixWithPagination($internalBucketId, $prefix, $currentPageNumber, $maxNumItemsPerPage);
        }

        $bucket = $this->configurationService->getBucketByID($bucketIdentifier);

        // create sharelinks
        $validFileDatas = [];
        foreach ($fileDatas as $fileData) {
            try {
                assert($fileData instanceof FileData);

                $fileData->setBucket($bucket);
                $fileData = $this->getLink($fileData);
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
        assert($uploadedFile === null || $uploadedFile instanceof UploadedFile);

        /* url decode according to RFC 3986 */
        $bucketID = $bucketID ? rawurldecode($bucketID) : null;
        $prefix = $prefix ? rawurldecode($prefix) : null;
        $notifyEmail = $notifyEmail ? rawurldecode($notifyEmail) : null;
        $retentionDuration = $retentionDuration ? rawurldecode($retentionDuration) : null;
        $type = $type ? rawurldecode($type) : null;

        if ($uploadedFile !== null) {
            if ($uploadedFile->getError() !== UPLOAD_ERR_OK) {
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
            $fileData->setMimeType($uploadedFile->getMimeType() ?? '');
            $fileData->setFilesize(filesize($uploadedFile->getRealPath()));
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
        $fileData->setBucket($this->getBucketByID($bucketID));

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

    public function checkAdditionalAuth(): bool
    {
        return $this->configurationService->checkAdditionalAuth();
    }

    /**
     * Sets the bucket of the given fileData and returns the bucket.
     *
     * @param FileData $fileData fileData which is missing the bucket
     */
    public function ensureBucket(FileData $fileData): Bucket
    {
        $bucket = $fileData->getBucket();
        if (!$bucket) {
            $bucket = $this->configurationService->getBucketByInternalID($fileData->getInternalBucketID());
            if (!$bucket) {
                throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'BucketID is not configured',
                    'blob:create-file-data-not-configured-bucket-id');
            }
            $fileData->setBucket($bucket);
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
    public function getBucketByInternalIdFromDatabase(string $bucketId): Bucket
    {
        $bucket = $this->em->getRepository(Bucket::class)->find($bucketId);

        if (!$bucket) {
            $newBucket = new Bucket();
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
     * @param Bucket $bucket bucket to be persisted
     *
     * @throws \Exception
     */
    public function saveBucketData(Bucket $bucket): void
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
        // get the service of the bucket
        $datasystemService = $this->datasystemService->getServiceByBucket($this->ensureBucket($fileData));

        // save the file using the connector
        return $datasystemService->saveFile($fileData);
    }

    /**
     * Saves the file using the connector.
     *
     * @param FileData $fileData fileData that carries the file which should be saved
     */
    public function saveFileFromString(FileData $fileData, string $data): ?FileData
    {
        // get the service of the bucket
        $datasystemService = $this->datasystemService->getServiceByBucket($fileData->getBucket());

        // save the file using the connector
        $fileData = $datasystemService->saveFileFromString($fileData, $data);

        return $fileData;
    }

    /**
     * Get HTTP link to binary content.
     *
     * @param FileData $fileData fileData for which a link should be provided
     *
     * @throws \Exception
     */
    public function getLink(FileData $fileData): FileData
    {
        // set bucket of fileData by bucketID
        $fileData->setBucket($this->configurationService->getBucketByInternalID($fileData->getInternalBucketID()));

        // get service from bucket
        $datasystemService = $this->datasystemService->getServiceByBucket($fileData->getBucket());

        // get HTTP link with connector for fileData
        $fileData = $datasystemService->getLink($fileData);

        // if !fileData, then something went wrong
        if (!$fileData) {
            throw ApiError::withDetails(Response::HTTP_FORBIDDEN, 'Link could not be generated', 'blob:file-data-invalid');
        }

        return $fileData;
    }

    /**
     * Get file content as base64 contentUrl.
     *
     * @param FileData $fileData fileData for which the base64 encoded file should be provided
     */
    public function getBase64Data(FileData $fileData): FileData
    {
        // set bucket of fileData
        $fileData->setBucket($this->configurationService->getBucketByInternalID($fileData->getInternalBucketID()));

        // get service of bucket
        $datasystemService = $this->datasystemService->getServiceByBucket($fileData->getBucket());

        // get base64 encoded file with connector
        $fileData = $datasystemService->getBase64Data($fileData);

        return $fileData;
    }

    /**
     * @throws \Exception
     */
    public function checkIntegrity(?OutputInterface $out = null, $sendEmail = true, $printIds = false)
    {
        $buckets = $this->configurationService->getBuckets();

        foreach ($buckets as $bucket) {
            try {
                $fileDatas = $this->getFileDataByBucketID($bucket->getIdentifier());
            } catch (\Exception $e) {
                $id = json_decode($e->getMessage(), true, 512, JSON_THROW_ON_ERROR)['errorId'];
                if ($id === 'blob:file-data-not-found') {
                    continue;
                } else {
                    throw $e;
                }
            }
            $invalidDatas = [];
            foreach ($fileDatas as $fileData) {
                try {
                    $data = $this->getBase64Data($fileData)->getContentUrl();
                    $content = base64_decode(explode(',', $data)[1], true);
                } catch (\Exception $e) {
                    $id = json_decode($e->getMessage(), true, 512, JSON_THROW_ON_ERROR)['errorId'];
                    if ($id === 'blob-connector-filesystem:file-not-found') {
                        $content = '';
                    } else {
                        throw $e;
                    }
                }

                /** @var FileData $fileData */
                if ($fileData->getFileHash() !== null && $content !== false && hash('sha256', $content) !== $fileData->getFileHash()) {
                    $invalidDatas[] = $fileData;
                } elseif ($fileData->getFileHash() !== null && hash('sha256', $fileData->getMetadata()) !== $fileData->getMetadataHash()) {
                    $invalidDatas[] = $fileData;
                }
                // dont overfill the email
                if ($sendEmail && count($invalidDatas) > 19) {
                    break;
                }
            }

            if ($sendEmail) {
                $this->sendIntegrityCheckMail($bucket, $invalidDatas);
            } elseif ($out !== null) {
                $this->printIntegrityCheck($bucket, $invalidDatas, $out, $printIds);
            }
        }
    }

    /**
     * Checks whether some files will expire soon, and sends a email to the bucket owner
     * or owner of the file (if configured as notifyEmail).
     *
     * @return void
     *
     * @throws \Exception
     */
    public function sendIntegrityCheckMail(Bucket $bucket, array $invalidDatas)
    {
        $integrityConfig = $bucket->getIntegrityCheckConfig();

        $id = $bucket->getIdentifier();
        $name = $bucket->getBucketID();

        if (!empty($invalidDatas)) {
            // create for each email to be notified an array with expiring filedatas
            $files = [];
            foreach ($invalidDatas as $fileData) {
                $file = [];
                /* @var ?FileData $fileData */
                $file['id'] = $fileData->getIdentifier();
                $file['fileName'] = $fileData->getFileName();
                $file['prefix'] = $fileData->getPrefix();
                $file['dateCreated'] = $fileData->getDateCreated()->format('c');
                $file['lastAccess'] = $fileData->getLastAccess()->format('c');
                if ($fileData->getDeleteAt() !== null) {
                    $file['deleteAt'] = $fileData->getDeleteAt()->format('c');
                } else {
                    $file['deleteAt'] = 'null';
                }
                if (empty($notifyEmails[$fileData->getNotifyEmail()])) {
                    $notifyEmails[$fileData->getNotifyEmail()] = [];
                }
                $files[] = $file;
            }

            $context = [
                'internalBucketId' => $id,
                'bucketId' => $name,
                'files' => $files,
            ];

            $config = $integrityConfig;
            $this->sendEmail($config, $context);
        }
    }

    /**
     * Checks whether some files will expire soon, and sends a email to the bucket owner
     * or owner of the file (if configured as notifyEmail).
     *
     * @return void
     *
     * @throws \Exception
     */
    private function printIntegrityCheck(Bucket $bucket, array $invalidDatas, OutputInterface $out, bool $printIds = false)
    {
        if (!empty($invalidDatas)) {
            $out->writeln('Found invalid data for bucket with bucket id: '.$bucket->getBucketID().' and internal bucket id: '.$bucket->getIdentifier());
            if ($printIds === true) {
                $out->writeln('The following blob file ids contain either invalid filedata or metadata:');
                // print all identifiers that failed the integrity check
                foreach ($invalidDatas as $fileData) {
                    /* @var ?FileData $fileData */
                    $out->writeln($fileData->getIdentifier());
                }
            }
            $out->writeln('In total, '.count($invalidDatas).' files are invalid!');
            $out->writeln(' ');
        } else {
            $out->writeln('No invalid data was found for bucket with bucket id: '.$bucket->getBucketID().' and internal bucket id'.$bucket->getIdentifier());
        }
    }

    /**
     * Get file as binary response.
     *
     * @param FileData $fileData fileData from which the file is taken
     */
    public function getBinaryResponse(FileData $fileData): Response
    {
        // get service of bucket
        $datasystemService = $this->datasystemService->getServiceByBucket($this->ensureBucket($fileData));

        // get binary response of file with connector
        return $datasystemService->getBinaryResponse($fileData);
    }

    /**
     * Generate HTTP link to blob resource.
     *
     * @throws \JsonException
     */
    public function generateGETLink(string $baseUrl, FileData $fileData, string $includeData = ''): string
    {
        $this->ensureBucket($fileData);

        // get time now
        $now = BlobUtils::now();

        // generate checksum and encode it in payload
        $payload = [
            'cs' => $this->generateChecksumFromFileData($fileData, 'GET', $now, $includeData),
        ];

        $url = '';
        if (!$includeData) {
            // set content url
            $filePath = $this->generateSignedContentUrl($fileData, 'GET', $now, $includeData, DenyAccessUnlessCheckSignature::create($fileData->getBucket()->getKey(), $payload));
            $url = $baseUrl.'/'.substr($filePath, 1);
        } else {
            try {
                $filePath = $this->getBase64Data($fileData)->getContentUrl();
                $url = $filePath.'';
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
            return '/blob/files/'.$fileData->getIdentifier().'?bucketID='.$fileData->getInternalBucketID().'&creationTime='.rawurlencode($now->format('c')).'&includeData=1&method='.$urlMethod.'&sig='.$sig;
        } else {
            return '/blob/files/'.$fileData->getIdentifier().'?bucketID='.$fileData->getInternalBucketID().'&creationTime='.rawurlencode($now->format('c')).'&method='.$urlMethod.'&sig='.$sig;
        }
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
            $contentUrl = '/blob/files/'.$fileData->getIdentifier().'?bucketID='.$fileData->getInternalBucketID().'&creationTime='.rawurlencode($now->format('c')).'&method='.$urlMethod;
        } else {
            // create url to hash
            $contentUrl = '/blob/files/'.$fileData->getIdentifier().'?bucketID='.$fileData->getInternalBucketID().'&creationTime='.rawurlencode($now->format('c')).'&includeData=1&method='.$urlMethod;
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
     * Get all the fileDatas of a given bucketID and prefix.
     */
    public function getFileDataByBucketIDAndPrefix(string $bucketID, string $prefix): array
    {
        $fileDatas = $this->em
            ->getRepository(FileData::class)
            ->findBy(['internalBucketId' => $bucketID, 'prefix' => $prefix]);

        if (!$fileDatas) {
            throw ApiError::withDetails(Response::HTTP_NOT_FOUND, 'FileDatas was not found!', 'blob:file-data-not-found');
        }

        return $fileDatas;
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

    /**
     * Get all the fileDatas of a given bucketID and prefix with pagination limits.
     */
    public function getFileDataByBucketIDAndPrefixWithPagination(
        string $bucketID, string $prefix, int $currentPageNumber, int $maxNumItemsPerPage): array
    {
        $query = $this->em
            ->getRepository(FileData::class)
            ->createQueryBuilder('f')
            ->where('f.internalBucketId = :bucketID')
            ->andWhere('f.prefix = :prefix')
            ->andWhere('f.deleteAt IS NULL')
            ->orderBy('f.dateCreated', 'ASC')
            ->setParameter('bucketID', $bucketID)
            ->setParameter('prefix', $prefix)
            ->setFirstResult($maxNumItemsPerPage * ($currentPageNumber - 1))
            ->setMaxResults($maxNumItemsPerPage);

        return $query->getQuery()->getResult();
    }

    /**
     * Get all the fileDatas of a given bucketID and prefix with pagination limits.
     */
    public function getFileDataByBucketIDAndPrefixAndIncludeDeleteAtWithPagination(
        string $bucketID, string $prefix, int $currentPageNumber, int $maxNumItemsPerPage): array
    {
        $query = $this->em
            ->getRepository(FileData::class)
            ->createQueryBuilder('f')
            ->where('f.internalBucketId = :bucketID')
            ->andWhere('f.prefix = :prefix')
            ->andWhere('f.deleteAt > :now OR f.deleteAt IS NULL')
            ->orderBy('f.dateCreated', 'ASC')
            ->setParameter('bucketID', $bucketID)
            ->setParameter('prefix', $prefix)
            ->setParameter('now', BlobUtils::now())
            ->setFirstResult($maxNumItemsPerPage * ($currentPageNumber - 1))
            ->setMaxResults($maxNumItemsPerPage);

        return $query->getQuery()->getResult();
    }

    /**
     * Get all the fileDatas of a given bucketID that start with prefix with pagination limits.
     */
    public function getFileDataByBucketIDAndStartsWithPrefixWithPagination(
        string $bucketID, string $prefix, int $currentPageNumber, int $maxNumItemsPerPage): array
    {
        $query = $this->em
            ->getRepository(FileData::class)
            ->createQueryBuilder('f')
            ->where('f.internalBucketId = :bucketID')
            ->andWhere('f.prefix LIKE :prefix')
            ->andWhere('f.deleteAt IS NULL')
            ->orderBy('f.dateCreated', 'ASC')
            ->setParameter('bucketID', $bucketID)
            ->setParameter('prefix', $prefix.'%')
            ->setFirstResult($maxNumItemsPerPage * ($currentPageNumber - 1))
            ->setMaxResults($maxNumItemsPerPage);

        return $query->getQuery()->getResult();
    }

    /**
     * Get all the fileDatas of a given bucketID that start with prefix with pagination limits.
     */
    public function getFileDataByBucketIDAndStartsWithPrefixAndIncludeDeleteAtWithPagination(
        string $bucketID, string $prefix, int $currentPageNumber, int $maxNumItemsPerPage): array
    {
        $query = $this->em
            ->getRepository(FileData::class)
            ->createQueryBuilder('f')
            ->where('f.internalBucketId = :bucketID')
            ->andWhere('f.prefix LIKE :prefix')
            ->andWhere('f.deleteAt > :now OR f.deleteAt is NULL')
            ->orderBy('f.dateCreated', 'ASC')
            ->setParameter('bucketID', $bucketID)
            ->setParameter('prefix', $prefix.'%')
            ->setParameter('now', BlobUtils::now())
            ->setFirstResult($maxNumItemsPerPage * ($currentPageNumber - 1))
            ->setMaxResults($maxNumItemsPerPage);

        return $query->getQuery()->getResult();
    }

    /**
     * Get size of the bucket with given bucketID.
     *
     * @throws NonUniqueResultException
     */
    public function getCurrentBucketSize(Bucket $bucket): int
    {
        $currentBucketSize = $bucket->getCurrentBucketSize();
        if ($currentBucketSize === null) {
            $query = $this->em
                ->getRepository(Bucket::class)
                ->createQueryBuilder('f')
                ->where('f.identifier = :bucketID')
                ->setParameter('bucketID', $bucket->getIdentifier())
                ->select('f.currentBucketSize as bucketSize');

            $oneOrNullResult = $query->getQuery()->getOneOrNullResult();
            $currentBucketSize = $oneOrNullResult === null ? 0 : $oneOrNullResult['bucketSize'];
            $bucket->setCurrentBucketSize($currentBucketSize);
        }

        return $currentBucketSize;
    }

    /**
     * Get all the fileDatas which expire in the defined time period by bucketID.
     *
     * @throws \Exception
     */
    public function getAllExpiringFiledatasByBucket(string $bucketID, int $limit = 10): array
    {
        $query = $this->em
            ->getRepository(FileData::class)
            ->createQueryBuilder('f')
            ->where('f.internalBucketId = :bucketID')
            ->orderBy('f.notifyEmail', 'ASC')
            ->orderBy('f.deleteAt', 'ASC')
            ->setParameter('bucketID', $bucketID)
            ->setMaxResults($limit);
        $result = $query->getQuery()->getResult();

        assert(is_array($result));

        return $result;
    }

    /**
     * Remove a given fileData from the entity manager.
     */
    private function removeFileData(FileData $fileData): void
    {
        $this->em->remove($fileData);
        $this->em->flush();

        $datasystemService = $this->datasystemService->getServiceByBucket($this->ensureBucket($fileData));
        $datasystemService->removeFile($fileData);
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
     * Sends a warning email with information about the buckets used quota.
     */
    public function sendQuotaWarning(Bucket $bucket, float $bucketQuotaByte): void
    {
        $notifyQuotaConfig = $bucket->getWarnQuotaOverConfig();

        $id = $bucket->getIdentifier();
        $name = $bucket->getBucketID();
        $quota = $bucket->getQuota();

        $context = [
            'internalBucketId' => $id,
            'bucketId' => $name,
            'quota' => $quota,
            'filledTo' => ($bucketQuotaByte / ($quota * 1024 * 1024)) * 100,
        ];

        $this->sendEmail($notifyQuotaConfig, $context);
    }

    /**
     * Sends reporting and bucket quota warning email if needed.
     */
    public function sendReporting(): void
    {
        $buckets = $this->configurationService->getBuckets();
        /*foreach ($buckets as $bucket) {
            if ($bucket->getReportingConfig() !== null) {
                // $this->sendReportingForBucket($bucket);
            }
        }*/
    }

    /**
     * Sends reporting and bucket quota warning email if needed.
     */
    public function sendWarning(): void
    {
        $buckets = $this->configurationService->getBuckets();
        foreach ($buckets as $bucket) {
            $this->sendBucketQuotaWarning($bucket);
        }
    }

    /**
     * Checks whether the bucket is filled to a preconfigured percentage, and sends a warning email if so.
     *
     * @throws NonUniqueResultException
     */
    public function sendBucketQuotaWarning(Bucket $bucket): void
    {
        // Check quota
        $bucketQuotaByte = $this->getCurrentBucketSize($bucket);
        $bucketWarningQuotaByte = $bucket->getQuota() * 1024 * 1024 * ($bucket->getNotifyWhenQuotaOver() / 100); // Convert mb to Byte and then calculate the warning quota
        if (floatval($bucketQuotaByte) > floatval($bucketWarningQuotaByte)) {
            $this->sendQuotaWarning($bucket, floatval($bucketQuotaByte));
        }
    }

    /**
     * Checks whether some files will expire soon, and sends a email to the bucket owner
     * or owner of the file (if configured as notifyEmail).
     *
     * @throws \Exception
     * @throws TransportExceptionInterface
     */
    public function sendReportingForBucket(Bucket $bucket): void
    {
        $reportingConfig = $bucket->getReportingConfig();
        $reportingEmail = $reportingConfig['to'];

        $id = $bucket->getIdentifier();
        $name = $bucket->getBucketID();
        $fileDatas = $this->getAllExpiringFiledatasByBucket($bucket->getIdentifier());

        if (!empty($fileDatas)) {
            // create for each email to be notified an array with expiring filedatas
            $notifyEmails = [];
            foreach ($fileDatas as $key => $fileData) {
                if ((int) $key === 10) {
                    break;
                }

                /* @var ?FileData $fileData */
                $file['id'] = $fileData->getIdentifier();
                $file['fileName'] = $fileData->getFileName();
                $file['prefix'] = $fileData->getPrefix();
                $file['dateCreated'] = $fileData->getDateCreated()->format('c');
                $file['lastAccess'] = $fileData->getLastAccess()->format('c');
                $file['deleteAt'] = $fileData->getDeleteAt()->format('c');
                if (empty($notifyEmails[$reportingEmail])) {
                    $notifyEmails[$reportingEmail] = [];
                }
                array_push($notifyEmails[$reportingEmail], $file);
            }

            foreach ($notifyEmails as $email => $files) {
                $context = [
                    'internalBucketId' => $id,
                    'bucketId' => $name,
                    'files' => $files,
                    'numFiles' => count($fileDatas),
                ];

                $config = $reportingConfig;
                // replace the default email with a given email
                /*if ($email) {
                    $config['to'] = $email;
                }*/
                $this->sendEmail($config, $context);
            }
        }
    }

    public function getBucketByID(string $bucketID): ?Bucket
    {
        return $this->configurationService->getBucketByID($bucketID);
    }

    /**
     * Wrapper to send an email from a given context.
     *
     * @throws TransportExceptionInterface
     * @throws LoaderError
     * @throws RuntimeError
     * @throws SyntaxError
     */
    private function sendEmail(array $config, array $context): void
    {
        $loader = new FilesystemLoader(__DIR__.'/../Resources/views/');
        $twig = new Environment($loader);

        $template = $twig->load($config['html_template']);
        $html = $template->render($context);

        $transport = Transport::fromDsn($config['dsn']);
        $mailer = new Mailer($transport);

        $email = (new Email())
            ->from($config['from'])
            ->to($config['to'])
            ->subject($config['subject'])
            ->html($html);

        $mailer->send($email);
    }

    public function checkConfig(): void
    {
        // Make sure the schema files exist
        foreach ($this->configurationService->getBuckets() as $bucket) {
            $this->getJsonSchemaStorageWithAllSchemasInABucket($bucket);
        }
    }

    public function checkFileSize(?OutputInterface $out = null, $sendEmail = true)
    {
        $buckets = $this->configurationService->getBuckets();

        // sum of file sizes in the blob_files table
        $sumBucketSizes = [];

        // sum thats saved for each bucket in the blob_bucket_sizes table
        $dbBucketSizes = [];

        // number of files that are in each bucket on the file system
        $countBucketSizes = [];

        foreach ($buckets as $bucket) {
            $config = $bucket->getBucketSizeConfig();
            if (!$sendEmail && $out !== null) {
                $out->writeln('Retrieving database information for bucket with bucket id: '.$bucket->getBucketID().' and internal bucket id: '.$bucket->getIdentifier());
                $out->writeln('Calculating sum of fileSizes in the blob_files table ...');
            }
            $query = $this->em
                ->getRepository(FileData::class)
                ->createQueryBuilder('f')
                ->where('f.internalBucketId = :bucketID')
                ->setParameter('bucketID', $bucket->getIdentifier())
                ->select('SUM(f.fileSize) as bucketSize');

            $result = $query->getQuery()->getOneOrNullResult();

            if ($result) {
                $bucketSize = $result['bucketSize'];

                // bucketSize will be null if there is no file in the bucket
                if ($bucketSize) {
                    $bucketSize = (int) $bucketSize;
                } else {
                    $bucketSize = 0;
                }

                $sumBucketSizes[$bucket->getIdentifier()] = $bucketSize;

                $dbBucket = $this->getBucketByInternalIdFromDatabase($bucket->getIdentifier());
                $savedBucketSize = $dbBucket->getCurrentBucketSize();

                $dbBucketSizes[$bucket->getIdentifier()] = $savedBucketSize;

                if (!$sendEmail && $out !== null) {
                    $out->writeln('Counting number of entries in the blob_files table ...');
                }

                $query = $this->em
                    ->getRepository(FileData::class)
                    ->createQueryBuilder('f')
                    ->where('f.internalBucketId = :bucketID')
                    ->setParameter('bucketID', $bucket->getIdentifier())
                    ->select('COUNT(f.identifier) as numOfItems');

                $result = $query->getQuery()->getOneOrNullResult();

                if ($result) {
                    $bucketFilesCount = $result['numOfItems'];

                    $countBucketSizes[$bucket->getIdentifier()] = $bucketFilesCount;
                }
            }
            if (!$sendEmail && $out !== null) {
                $out->writeln(' ');
            }
        }

        foreach ($buckets as $bucket) {
            if (!$sendEmail && $out !== null) {
                $out->writeln('Retrieving filesystem information for bucket with bucket id: '.$bucket->getBucketID().' and internal bucket id: '.$bucket->getIdentifier());
                $out->writeln('Calculating sum of fileSizes in the bucket directory ...');
            }
            $service = $this->datasystemService->getServiceByBucket($bucket);
            $filebackendSize = $service->getSumOfFilesizesOfBucket($bucket);

            if (!$sendEmail && $out !== null) {
                $out->writeln('Counting number of files in the bucket directory ...');
            }

            $filebackendNumOfFiles = $service->getNumberOfFilesInBucket($bucket);

            $bucketSize = $sumBucketSizes[$bucket->getIdentifier()];
            $savedBucketSize = $dbBucketSizes[$bucket->getIdentifier()];
            $bucketFilesCount = $countBucketSizes[$bucket->getIdentifier()];

            if (($bucketSize !== $savedBucketSize || $bucketSize !== $filebackendSize || $savedBucketSize !== $filebackendSize) || $filebackendNumOfFiles !== $bucketFilesCount) {
                $context = [
                    'internalBucketId' => $bucket->getIdentifier(),
                    'bucketId' => $bucket->getBucketID(),
                    'blobFilesSize' => $bucketSize,
                    'blobFilesCount' => $bucketFilesCount,
                    'blobBucketSizes' => $savedBucketSize,
                    'blobBackendSize' => $filebackendSize,
                    'blobBackendCount' => $filebackendNumOfFiles,
                ];

                if ($sendEmail) {
                    $this->sendEmail($config, $context);
                } elseif ($out !== null) {
                    $this->printFileSizeCheck($out, $context);
                }
            } else {
                if (!$sendEmail && $out !== null) {
                    $out->writeln('Everything as expected!');
                    $out->writeln(' ');
                }
            }
        }
    }

    public function getJsonSchemaStorageWithAllSchemasInABucket(Bucket $bucket): SchemaStorage
    {
        $schemaStorage = new SchemaStorage();
        foreach (($bucket->getAdditionalTypes() ?? []) as $type => $path) {
            $jsonSchemaObject = json_decode(file_get_contents($path));

            if ($jsonSchemaObject === null) {
                throw ApiError::withDetails(Response::HTTP_INTERNAL_SERVER_ERROR, 'JSON Schemas for the schema storage could not be loaded', 'blob:create-file-data-json-schema-storage-load-error');
            }
            $name = explode('/', $path);

            $schemaStorage->addSchema('file://'.$this->kernel->getProjectDir().'/public/'.end($name), $jsonSchemaObject);
        }

        return $schemaStorage;
    }

    private function printFileSizeCheck(OutputInterface $out, array $context): void
    {
        $out->writeln('Sum of sizes of the blob_files table: '.$context['blobFilesSize']);
        $out->writeln('Number of entries in the blob_files table: '.$context['blobFilesCount']);
        $out->writeln('Stored sum of sizes in the blob_bucket_sizes table: '.$context['blobBucketSizes']);
        $out->writeln('Sum of sizes in the storage backend: '.$context['blobBackendSize']);
        $out->writeln('Number of files in the storage backend: '.$context['blobBackendCount']);
        $out->writeln(' ');
    }

    public function getAdditionalAuthFromConfig(): bool
    {
        return $this->configurationService->checkAdditionalAuth();
    }

    /**
     * @throws \Exception
     */
    public function writeToTablesAndSaveFileData(FileData $fileData, int $bucketSizeDeltaByte, string $errorPrefix): void
    {
        /* Check quota */
        $bucketQuotaByte = $this->ensureBucket($fileData)->getQuota() * 1024 * 1024; // Convert mb to Byte
        $bucket = $this->getBucketByInternalIdFromDatabase($fileData->getInternalBucketID());
        $newBucketSizeByte = max($bucket->getCurrentBucketSize() + $bucketSizeDeltaByte, 0);
        if ($newBucketSizeByte > $bucketQuotaByte) {
            throw ApiError::withDetails(Response::HTTP_INSUFFICIENT_STORAGE, 'Bucket quota is reached',
                $errorPrefix.'-bucket-quota-reached');
        }
        $bucket->setCurrentBucketSize($newBucketSizeByte);

        // Return correct data for service and save the data
        $fileData = $this->saveFile($fileData);
        if (!$fileData) {
            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'data upload failed',
                $errorPrefix.'-data-upload-failed');
        }

        // try to update bucket size
        $this->em->getConnection()->beginTransaction();
        try {
            $this->saveBucketData($bucket);
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
        $bucket = $this->getBucketByInternalIdFromDatabase($fileData->getInternalBucketID());
        $newBucketSizeByte = max($bucket->getCurrentBucketSize() + $bucketSizeDeltaByte, 0);
        $bucket->setCurrentBucketSize($newBucketSizeByte);

        // try to update bucket size
        $this->em->getConnection()->beginTransaction();
        try {
            $this->saveBucketData($bucket);
            $this->removeFileData($fileData);

            $this->em->getConnection()->commit();
        } catch (\Exception $e) {
            $this->em->getConnection()->rollBack();
            throw ApiError::withDetails(Response::HTTP_INTERNAL_SERVER_ERROR, 'Error while removing the file data',
                'blob:remove-file-data-save-file-failed');
        }
    }

    /**
     * Checks if given filedata is valid
     * intended for use before data retrieval using GET.
     */
    public function checkFileDataBeforeRetrieval(FileData $fileData, Bucket $bucket, string $errorPrefix): void
    {
        $content = base64_decode(explode(',', $this->getBase64Data($fileData)->getContentUrl())[1], true);

        if (!$content) {
            throw ApiError::withDetails(Response::HTTP_CONFLICT, 'file data cannot be decoded', $errorPrefix.'-decode-fail');
        }
        // check if file integrity should be checked and if so check it
        if ($this->doFileIntegrityChecks() && $fileData->getFileHash() !== null && hash('sha256', $content) !== $fileData->getFileHash()) {
            throw ApiError::withDetails(Response::HTTP_CONFLICT, 'sha256 file hash doesnt match! File integrity cannot be guaranteed', $errorPrefix.'-file-hash-mismatch');
        }
        if ($this->doFileIntegrityChecks() && $fileData->getMetadataHash() !== null && hash('sha256', $fileData->getMetadata()) !== $fileData->getMetadataHash()) {
            throw ApiError::withDetails(Response::HTTP_CONFLICT, 'sha256 metadata hash doesnt match! Metadata integrity cannot be guaranteed', $errorPrefix.'-metadata-hash-mismatch');
        }

        $additionalMetadata = $fileData->getMetadata();
        $additionalType = $fileData->getType();

        // check if metadata is a valid json
        if ($additionalMetadata && !json_decode($additionalMetadata, true)) {
            throw ApiError::withDetails(Response::HTTP_CONFLICT, 'Bad metadata', $errorPrefix.'-bad-metadata');
        }

        // check if additionaltype is defined
        if ($additionalType && !array_key_exists($additionalType, $bucket->getAdditionalTypes())) {
            throw ApiError::withDetails(Response::HTTP_CONFLICT, 'Bad type', $errorPrefix.'-bad-type');
        }
        $schemaStorage = $this->getJsonSchemaStorageWithAllSchemasInABucket($bucket);
        $validator = new Validator(new Factory($schemaStorage));
        $metadataDecoded = (object) json_decode($additionalMetadata);

        // check if given additionalMetadata json has the same keys like the defined additionalType
        if ($additionalType && $additionalMetadata && $validator->validate($metadataDecoded, (object) ['$ref' => 'file://'.realpath($bucket->getAdditionalTypes()[$additionalType])]) !== 0) {
            $messages = [];
            foreach ($validator->getErrors() as $error) {
                $messages[$error['property']] = $error['message'];
            }
            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'metadata does not match specified type', $errorPrefix.'-metadata-does-not-match-type', $messages);
        }
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

        // TODO: is empty string 'metadata' allowed?

        // check if metadata is a valid json
        $metadataDecoded = null;
        if ($fileData->getMetadata()) {
            $metadataDecoded = json_decode($fileData->getMetadata());
            if (!$metadataDecoded) {
                throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'Bad metadata', $errorPrefix.'-bad-metadata');
            }
        }

        $bucket = $this->ensureBucket($fileData);

        // check if additional type is defined
        if ($fileData->getType() !== null) {
            if (!array_key_exists($fileData->getType(), $bucket->getAdditionalTypes())) {
                throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'Bad type', $errorPrefix.'-bad-type');
            }

            /* check if given metadata json has the same keys as the defined type */
            $schemaStorage = $this->getJsonSchemaStorageWithAllSchemasInABucket($bucket);
            $jsonSchemaObject = json_decode(file_get_contents($bucket->getAdditionalTypes()[$fileData->getType()]));

            $validator = new Validator(new Factory($schemaStorage));
            if ($validator->validate($metadataDecoded, $jsonSchemaObject) !== 0) {
                $messages = [];
                foreach ($validator->getErrors() as $error) {
                    $messages[$error['property']] = $error['message'];
                }
                throw ApiError::withDetails(Response::HTTP_BAD_REQUEST,
                    'metadata does not match specified type', $errorPrefix.'-metadata-does-not-match-type', $messages);
            }
        }

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
