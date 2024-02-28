<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\State;

use Dbp\Relay\BlobBundle\Entity\FileData;
use Dbp\Relay\BlobBundle\Helper\DenyAccessUnlessCheckSignature;
use Dbp\Relay\BlobBundle\Service\BlobService;
use Dbp\Relay\CoreBundle\Exception\ApiError;
use Dbp\Relay\CoreBundle\Rest\AbstractDataProvider;
use JsonSchema\Validator;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;

class FileDataProvider extends AbstractDataProvider
{
    /**
     * @var BlobService
     */
    private $blobService;

    /**
     * @var RequestStack
     */
    private $requestStack;

    public function __construct(BlobService $blobService, RequestStack $requestStack)
    {
        parent::__construct();
        $this->blobService = $blobService;
        $this->requestStack = $requestStack;
    }

    protected function isUserGrantedOperationAccess(int $operation): bool
    {
        return true;
    }

    protected function getItemById(string $id, array $filters = [], array $options = []): ?object
    {
        return $this->getFileDataById($id, $filters);
    }

    /**
     * @throws \JsonException
     * @throws \Exception
     */
    protected function getFileDataById($id, array $filters): object
    {
        // check if the minimal needed parameters are present and correct
        $errorPrefix = 'blob:get-file-data-by-id';
        DenyAccessUnlessCheckSignature::checkMinimalParameters($errorPrefix, $this->blobService, $this->requestStack->getCurrentRequest(), $filters, ['GET', 'PATCH', 'DELETE']);

        // get secret of bucket
        $bucketID = rawurldecode($filters['bucketID']) ?? '';
        $secret = $this->blobService->getSecretOfBucketWithBucketID($bucketID);

        // check if signature is valid
        DenyAccessUnlessCheckSignature::checkSignature($secret, $this->requestStack->getCurrentRequest(), $this->blobService);

        // get file data associated with the given identifier
        $fileData = $this->blobService->getFileData($id);

        // check if fileData is null
        if (!$fileData) {
            throw ApiError::withDetails(Response::HTTP_NOT_FOUND, 'FileData was not found!', 'blob:file-data-not-found');
        }

        // get used method of request
        $method = $this->requestStack->getCurrentRequest()->getMethod();

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
                $body = json_decode($this->requestStack->getCurrentRequest()->getContent(), true);
                $fileName = $body['fileName'] ?? '';
                $additionalMetadata = $body['additionalMetadata'] ?? '';
                $additionalType = $body['additionalType'] ?? '';
                $prefix = $body['prefix'] ?? '';
                $existsUntil = $body['existsUntil'] ?? '';
                $notifyEmail = $body['notifyEmail'] ?? '';
                $file = $body['file'] ?? '';

                // throw error if not field is provided
                if (!$fileName && !$additionalMetadata & !$additionalType && !$prefix && !$existsUntil && !$notifyEmail && !$file) {
                    throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'at least one field to patch has to be provided', 'blob:patch-file-data-missing');
                }

                if ($fileName) {
                    assert(is_string($fileName));
                    $fileData->setFileName($fileName);
                }
                $bucket = $this->blobService->getBucketByID($bucketID);

                if ($additionalType) {
                    assert(is_string($additionalType));

                    if (!array_key_exists($additionalType, $bucket->getAdditionalTypes())) {
                        throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'Bad additionalType', 'blob:patch-file-data-bad-additional-type');
                    }
                    $fileData->setAdditionalType($additionalType);
                }

                if ($additionalMetadata) {
                    if (!json_decode($additionalMetadata, true)) {
                        throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'Given additionalMetadata is no valid JSON!', 'blob:patch-file-data-bad-additionalMetadata');
                    }
                    $storedType = $fileData->getAdditionalType();
                    if ($storedType) {
                        $validator = new Validator();
                        $metadataDecoded = json_decode($additionalMetadata);

                        if ($validator->validate($metadataDecoded, (object) json_decode($bucket->getAdditionalTypes()[$storedType])) !== 0) {
                            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'Given additionalMetadata does not fit additionalType schema!', 'blob:patch-file-data-additionalType-mismatch');
                        }
                    }
                    assert(is_string($additionalMetadata));
                    $fileData->setAdditionalMetadata($additionalMetadata);
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
                        throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'Given existsUntil is in an invalid format!', 'blob:patch-file-data-existsUntil-bad-format');
                    }
                    $fileData->setExistsUntil($date);
                }

                if ($notifyEmail) {
                    assert(is_string($notifyEmail));
                    $fileData->setNotifyEmail($notifyEmail);
                }

                if ($file) {
                    assert(is_string($file));
                    $fileDecoded = base64_decode($file, true);

                    // check if is valid b64
                    if ($fileDecoded) {
                        //$fileData->setFile($fileDecoded);
                        //$fileData->setMimeType();
                        $fileData = $this->blobService->saveFileFromString($fileData, $fileDecoded);

                        $fileObj = $fileData->getFile();

                        $fileData->setFileHash(hash('sha256', $fileObj->getContent()));
                        $fileData->setMimeType($fileObj->getMimeType());
                        $fileData->setFileSize($fileObj->getSize());

                        if (!$fileData) {
                            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'data upload failed', 'blob:create-file-data-data-upload-failed');
                        }
                    } else {
                        throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'Given file is in an invalid format!', 'blob:patch-file-data-file-bad-format');
                    }
                }

                $fileData->setDateModified($time);
            }
            // check if GET request was used
            elseif ($method === 'GET') {
                $content = base64_decode(explode(',', $this->blobService->getBase64Data($fileData)->getContentUrl())[1], true);

                if (!$content) {
                    throw ApiError::withDetails(Response::HTTP_CONFLICT, 'file data cannot be decoded', 'blob:file-data-decode-fail');
                }
                // check if file integrity should be checked and if so check it
                if ($this->blobService->doFileIntegrityChecks() && $fileData->getFileHash() !== null && hash('sha256', $content) !== $fileData->getFileHash()) {
                    throw ApiError::withDetails(Response::HTTP_CONFLICT, 'sha256 file hash doesnt match! File integrity cannot be guaranteed', 'blob:file-hash-mismatch');
                }
                if ($this->blobService->doFileIntegrityChecks() && $fileData->getMetadataHash() !== null && hash('sha256', $fileData->getAdditionalMetadata()) !== $fileData->getMetadataHash()) {
                    throw ApiError::withDetails(Response::HTTP_CONFLICT, 'sha256 metadata hash doesnt match! Metadata integrity cannot be guaranteed', 'blob:metadata-hash-mismatch');
                }

                // check if includeData parameter is set
                $includeData = $filters['includeData'] ?? '';
                if ($includeData === '1') {
                    $fileData = $this->blobService->getBase64Data($fileData);
                } else {
                    $fileData = $this->blobService->getLink($fileData);
                }
            }
        }

        $this->blobService->saveFileData($fileData);

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
        $bucketID = rawurldecode($filters['bucketID'] ?? '');

        // get prefix by filters
        $prefix = rawurldecode($filters['prefix'] ?? '');

        // check if signature and checksum is correct
        $secret = $this->blobService->getSecretOfBucketWithBucketID($bucketID);
        DenyAccessUnlessCheckSignature::checkSignature($secret, $this->requestStack->getCurrentRequest(), $this->blobService);

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
        foreach ($fileDatas as &$fileData) {
            try {
                assert($fileData instanceof FileData);
                $this->blobService->saveFileData($fileData);
                $fileData->setBucket($this->blobService->configurationService->getBucketByID($bucketID));
                $fileData = $this->blobService->getLink($fileData);
                $baseUrl = $this->requestStack->getCurrentRequest()->getSchemeAndHttpHost();
                $fileData->setContentUrl($this->blobService->generateGETLink($baseUrl, $fileData, $includeData));
            } catch (\Exception $e) {
                // skip file not found
                // TODO how to handle this correctly? This should never happen in the first place
                if ($e->getCode() === 404) {
                    continue;
                }
            }
        }

        return $fileDatas;
    }
}
