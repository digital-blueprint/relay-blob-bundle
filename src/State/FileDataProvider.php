<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\State;

use Dbp\Relay\BlobBundle\Entity\FileData;
use Dbp\Relay\BlobBundle\Event\ChangeFileDataByPatchSuccessEvent;
use Dbp\Relay\BlobBundle\Event\DeleteFileDataByDeleteSuccessEvent;
use Dbp\Relay\BlobBundle\Helper\BlobUtils;
use Dbp\Relay\BlobBundle\Helper\DenyAccessUnlessCheckSignature;
use Dbp\Relay\BlobBundle\Service\BlobService;
use Dbp\Relay\CoreBundle\Exception\ApiError;
use Dbp\Relay\CoreBundle\Rest\AbstractDataProvider;
use JsonSchema\Constraints\Factory;
use JsonSchema\Validator;
use Symfony\Bridge\PsrHttpMessage\Factory\UploadedFile;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * @extends AbstractDataProvider<FileData>
 */
class FileDataProvider extends AbstractDataProvider
{
    private BlobService $blobService;
    private RequestStack $requestStack;
    private EventDispatcherInterface $eventDispatcher;

    public function __construct(BlobService $blobService, RequestStack $requestStack, EventDispatcherInterface $eventDispatcher)
    {
        parent::__construct();

        $this->blobService = $blobService;
        $this->requestStack = $requestStack;
        $this->eventDispatcher = $eventDispatcher;
    }

    protected function requiresAuthentication(int $operation): bool
    {
        return $this->blobService->getAdditionalAuthFromConfig();
    }

    /**
     * @throws \JsonException
     */
    protected function getItemById(string $id, array $filters = [], array $options = []): ?FileData
    {
        return $this->getFileDataById($id, $filters);
    }

    /**
     * @throws \JsonException
     * @throws \Exception
     */
    protected function getFileDataById($id, array $filters): object
    {
        if (!Uuid::isValid($id)) {
            throw ApiError::withDetails(Response::HTTP_NOT_FOUND, 'Identifier is in an invalid format!', 'blob:identifier-invalid-format');
        }

        // get current request
        $request = $this->requestStack->getCurrentRequest();

        // get used method of request
        $method = $this->requestStack->getCurrentRequest()->getMethod();

        // check if the minimal needed parameters are present and correct
        $errorPrefix = 'blob:get-file-data-by-id';

        DenyAccessUnlessCheckSignature::checkMinimalParameters($errorPrefix, $this->blobService, $request, $filters, ['GET', 'PATCH', 'DELETE']);

        // get secret of bucket
        $bucketID = rawurldecode($filters['bucketIdentifier']) ?? '';
        $secret = $this->blobService->getSecretOfBucketWithBucketID($bucketID);

        // check if signature is valid
        DenyAccessUnlessCheckSignature::checkSignature($secret, $request, $this->blobService, $this->isAuthenticated(), $this->blobService->checkAdditionalAuth());
        // get file data associated with the given identifier
        $fileData = $this->blobService->getFileData($id);

        // check if fileData is null
        if (!$fileData || ($fileData->getExistsUntil() !== null && $fileData->getExistsUntil() < new \DateTimeImmutable()) || ($fileData->getExistsUntil() === null && $fileData->getDateCreated()->add(new \DateInterval($this->blobService->getBucketByID($bucketID)->getMaxRetentionDuration())) < new \DateTimeImmutable())) {
            throw ApiError::withDetails(Response::HTTP_NOT_FOUND, 'FileData was not found!', 'blob:file-data-not-found');
        }

        // get the current time to save it as last access / last modified
        $time = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        // decide by method what to execute
        if ($method !== 'DELETE') {
            // create shareLink
            $fileData = $this->blobService->getLink($fileData);

            // check if filedata is null
            assert(!is_null($fileData));

            // check if PATCH request was used
            if ($method === 'PATCH') {
                /* get from body */
                $body = BlobUtils::getFieldsFromPatchRequest($request);
                $file = $body['file'] ?? '';
                $fileHash = $body['fileHash'] ?? '';
                $fileName = $body['fileName'] ?? '';
                $additionalMetadata = $body['metadata'] ?? '';
                $metadataHash = $body['metadataHash'] ?? '';

                /* get from url */
                $additionalType = $filters['type'] ?? '';
                $prefix = $filters['prefix'] ?? '';
                $existsUntil = $filters['existsUntil'] ?? '';
                $notifyEmail = $filters['notifyEmail'] ?? '';

                // throw error if no field is provided
                if (!$fileName && !$additionalMetadata & !$additionalType && !$prefix && !$existsUntil && !$notifyEmail && !$file) {
                    throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'at least one field to patch has to be provided', 'blob:patch-file-data-missing');
                }

                if ($fileName) {
                    assert(is_string($fileName));
                    $fileData->setFileName($fileName);
                }
                $bucket = $this->blobService->getBucketByID($bucketID);

                if (array_key_exists('type', $filters)) {
                    assert(is_string($additionalType));

                    if (!empty($additionalType) && !array_key_exists($additionalType, $bucket->getAdditionalTypes())) {
                        throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'Bad type', 'blob:patch-file-data-bad-type');
                    }
                    $fileData->setType($additionalType);
                }

                if ($additionalMetadata) {
                    if (!json_decode($additionalMetadata, true)) {
                        throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'Given metadata is no valid JSON!', 'blob:patch-file-data-bad-metadata');
                    }
                    $storedType = $fileData->getType();
                    if ($storedType) {
                        $schemaStorage = $this->blobService->getJsonSchemaStorageWithAllSchemasInABucket($bucket);
                        $validator = new Validator(new Factory($schemaStorage));
                        $metadataDecoded = json_decode($additionalMetadata);

                        if (array_key_exists('type', $filters) && !empty($additionalType) && $validator->validate($metadataDecoded, (object) ['$ref' => 'file://'.realpath($bucket->getAdditionalTypes()[$additionalType])]) !== 0) {
                            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'Given metadata does not fit type schema!', 'blob:patch-file-data-type-mismatch');
                        }
                    }
                    $hash = hash('sha256', $additionalMetadata);
                    if ($metadataHash && $hash !== $metadataHash) {
                        throw ApiError::withDetails(Response::HTTP_FORBIDDEN, 'Metadata hash change forbidden', 'blob:patch-file-data-metadata-hash-change-forbidden');
                    }
                    assert(is_string($additionalMetadata));
                    $fileData->setMetadata($additionalMetadata);
                    $fileData->setMetadataHash(hash('sha256', $additionalMetadata));
                }

                if ($prefix) {
                    assert(is_string($prefix));
                    $fileData->setPrefix($prefix);
                }

                if ($existsUntil) {
                    assert(is_string($existsUntil));

                    // check if date can be created
                    $date = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $existsUntil);
                    if ($date === false) {
                        // RFC3339_EXTENDED is broken in PHP
                        $date = \DateTimeImmutable::createFromFormat("Y-m-d\TH:i:s.uP", $existsUntil);
                    }
                    if ($date === false) {
                        throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'Given existsUntil is in an invalid format!', 'blob:patch-file-data-exists-until-bad-format');
                    }
                    $fileData->setExistsUntil($date);
                }

                if ($notifyEmail) {
                    assert(is_string($notifyEmail));
                    $fileData->setNotifyEmail($notifyEmail);
                }

                if ($file instanceof UploadedFile) {
                    $oldSize = $fileData->getFileSize();

                    // Check quota
                    $bucketSize = $this->blobService->getCurrentBucketSize($fileData->getInternalBucketID());
                    if ($bucketSize !== null) {
                        $bucketsizeByte = (int) $bucketSize['bucketSize'];
                    } else {
                        $bucketsizeByte = 0;
                    }
                    $bucketQuotaByte = $fileData->getBucket()->getQuota() * 1024 * 1024; // Convert mb to Byte
                    $newBucketSizeByte = $bucketsizeByte + $file->getSize();
                    if ($newBucketSizeByte > $bucketQuotaByte) {
                        throw ApiError::withDetails(Response::HTTP_INSUFFICIENT_STORAGE, 'Bucket quota is reached', 'blob:patch-file-data-bucket-quota-reached');
                    }

                    /* check hash of file */
                    $hash = hash('sha256', $file->getContent());
                    if ($fileHash && $hash !== $fileHash) {
                        throw ApiError::withDetails(Response::HTTP_FORBIDDEN, 'File hash change forbidden', 'blob:patch-file-data-file-hash-change-forbidden');
                    }

                    $fileData->setFile($file);
                    $fileData->setMimeType($file->getMimeType() ?? '');
                    $fileData->setFileSize($file->getSize());
                    $fileData->setFileHash(hash('sha256', $file->getContent()));

                    $fileData->setDateModified($time);

                    $docBucket = $this->blobService->getBucketByInternalIdFromDatabase($fileData->getInternalBucketID());
                    $this->blobService->writeToTablesAndChangeFileData($fileData, $docBucket->getCurrentBucketSize() - $oldSize + $fileData->getFileSize());
                }

                $patchSuccessEvent = new ChangeFileDataByPatchSuccessEvent($fileData);
                $this->eventDispatcher->dispatch($patchSuccessEvent);
            }
            // check if GET request was used
            elseif ($method === 'GET') {
                $errorPrefix = 'blob:get-file-data';

                // check if output validation shouldnt be checked
                // a user can get the data even if the system usually would throw and invalid data error
                $disableValidation = $filters['disableOutputValidation'] ?? '';
                if (!($disableValidation === '1') && $this->blobService->configurationService->getOutputValidationForBucketId($bucketID)) {
                    $this->blobService->checkFileDataBeforeRetrieval($fileData, $bucketID, $errorPrefix);
                }

                // check if the base64 encoded data should be returned, not only the metadata
                $includeData = $filters['includeData'] ?? '';
                if ($includeData === '1') {
                    $fileData = $this->blobService->getBase64Data($fileData);
                } else {
                    $fileData = $this->blobService->getLink($fileData);
                }
            }
        } else {
            $deleteSuccessEvent = new DeleteFileDataByDeleteSuccessEvent($fileData);
            $this->eventDispatcher->dispatch($deleteSuccessEvent);
        }

        $this->blobService->saveFileData($fileData);
        if ($fileData->getExistsUntil() === null) {
            $fileData->setExistsUntil($fileData->getDateCreated()->add(new \DateInterval($this->blobService->getDefaultRetentionDurationByBucketId($bucketID))));
        }

        return $fileData;
    }

    /**
     * @throws \JsonException
     * @throws \Exception
     */
    protected function getPage(int $currentPageNumber, int $maxNumItemsPerPage, array $filters = [], array $options = []): array
    {
        // set error prefix
        $errorPrefix = 'blob:get-file-data-collection';

        // check if minimal parameters for the request are present and valid
        DenyAccessUnlessCheckSignature::checkMinimalParameters($errorPrefix, $this->blobService, $this->requestStack->getCurrentRequest(), $filters, ['GET']);

        // get bucketID after check
        $bucketID = rawurldecode($filters['bucketIdentifier'] ?? '');

        // get prefix by filters
        $prefix = rawurldecode($filters['prefix'] ?? '');

        // check if signature and checksum is correct
        $secret = $this->blobService->getSecretOfBucketWithBucketID($bucketID);

        DenyAccessUnlessCheckSignature::checkSignature($secret, $this->requestStack->getCurrentRequest(), $this->blobService, $this->isAuthenticated(), $this->blobService->checkAdditionalAuth());

        // get includeData param and decode it
        $includeData = rawurldecode($filters['includeData'] ?? '');
        $startsWith = rawurldecode($filters['startsWith'] ?? '');
        assert(is_string($includeData));

        $internalBucketId = $this->blobService->getInternalBucketIdByBucketID($bucketID);

        // get file data of bucket for current page, and decide whether prefix should be used as 'startsWith' or not
        if ($startsWith) {
            $fileDatas = $this->blobService->getFileDataByBucketIDAndStartsWithPrefixWithPagination($internalBucketId, $prefix, $currentPageNumber, $maxNumItemsPerPage);
        } else {
            $fileDatas = $this->blobService->getFileDataByBucketIDAndPrefixWithPagination($internalBucketId, $prefix, $currentPageNumber, $maxNumItemsPerPage);
        }

        // create sharelinks
        $validFileDatas = [];
        foreach ($fileDatas as $fileData) {
            try {
                assert($fileData instanceof FileData);
                if (!$fileData->getExistsUntil()) {
                    $fileData->setExistsUntil($fileData->getDateCreated()->add(new \DateInterval($this->blobService->getDefaultRetentionDurationByBucketId($bucketID))));
                }

                if ($fileData->getExistsUntil() < new \DateTimeImmutable()) {
                    continue;
                }

                $fileData->setBucket($this->blobService->configurationService->getBucketByID($bucketID));
                $fileData = $this->blobService->getLink($fileData);
                $baseUrl = $this->requestStack->getCurrentRequest()->getSchemeAndHttpHost();
                $fileData->setContentUrl($this->blobService->generateGETLink($baseUrl, $fileData, $includeData));

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
}
