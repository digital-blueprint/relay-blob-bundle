<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\State;

use Dbp\Relay\BlobBundle\Entity\FileData;
use Dbp\Relay\BlobBundle\Helper\DenyAccessUnlessCheckSignature;
use Dbp\Relay\BlobBundle\Service\BlobService;
use Dbp\Relay\CoreBundle\Exception\ApiError;
use Dbp\Relay\CoreBundle\Rest\AbstractDataProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * @internal
 *
 * @extends AbstractDataProvider<FileData>
 */
class FileDataProvider extends AbstractDataProvider
{
    public function __construct(
        private readonly BlobService $blobService,
        private readonly RequestStack $requestStack)
    {
        parent::__construct();
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
    protected function getFileDataById(string $id, array $filters): object
    {
        $request = $this->requestStack->getCurrentRequest();
        $isGetRequest = $request->getMethod() === Request::METHOD_GET;

        $errorPrefix = 'blob:get-file-data-by-id';
        DenyAccessUnlessCheckSignature::checkSignature(
            $errorPrefix, $this->blobService, $request, $filters, ['GET', 'PATCH', 'DELETE']);

        if (!Uuid::isValid($id)) {
            throw ApiError::withDetails(Response::HTTP_NOT_FOUND, 'Identifier is in an invalid format!', 'blob:identifier-invalid-format');
        }

        // check if output validation shouldn't be checked
        // a user can get the data even if the system usually would throw and invalid data error
        $disableOutputValidation = ($filters['disableOutputValidation'] ?? '') === '1';
        $includeFileContent = ($filters['includeData'] ?? '') === '1';
        $updateLastAccessTime = $isGetRequest; // PATCH: saves for itself, DELETE: will be deleted anyway

        $fileData = $this->blobService->getFile($id, $disableOutputValidation, $includeFileContent, $updateLastAccessTime);

        $bucket = $this->blobService->ensureBucket($fileData);
        $bucketID = rawurldecode($filters['bucketIdentifier'] ?? '');
        if ($bucket->getBucketID() !== $bucketID) {
            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST,
                'Provided bucket ID does not match with the bucket ID of the file!', 'blob:bucket-id-mismatch');
        }

        // don't throw on DELETE and PATCH requests
        if ($isGetRequest) {
            $includeDeleteAt = rawurldecode($filters['includeDeleteAt'] ?? '');
            if (!$includeDeleteAt && $fileData->getDeleteAt() !== null) {
                throw ApiError::withDetails(Response::HTTP_NOT_FOUND, 'FileData was not found!', 'blob:file-data-not-found');
            }
        }

        return $fileData;
    }

    /**
     * @throws \JsonException
     * @throws \Exception
     */
    protected function getPage(int $currentPageNumber, int $maxNumItemsPerPage, array $filters = [], array $options = []): array
    {
        $errorPrefix = 'blob:get-file-data-collection';

        // check if minimal parameters for the request are present and valid
        DenyAccessUnlessCheckSignature::checkSignature(
            $errorPrefix, $this->blobService, $this->requestStack->getCurrentRequest(), $filters, ['GET']);

        // get bucketID after check
        $bucketID = rawurldecode($filters['bucketIdentifier'] ?? '');

        // get prefix by filters
        $prefix = rawurldecode($filters['prefix'] ?? '');

        // get includeData param and decode it
        $includeData = rawurldecode($filters['includeData'] ?? '');
        $startsWith = rawurldecode($filters['startsWith'] ?? '');
        $includeDeleteAt = rawurldecode($filters['includeDeleteAt'] ?? '');

        assert(is_string($includeData));
        assert(is_string($startsWith));
        assert(is_string($includeDeleteAt));

        $internalBucketId = $this->blobService->getInternalBucketIdByBucketID($bucketID);

        // TODO: make the upper limit configurable
        // hard limit page size
        if ($maxNumItemsPerPage > 10000) {
            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'Requested too many items per page', $errorPrefix.'-too-many-items-per-page');
        }

        // get file data of bucket for current page, and decide whether prefix should be used as 'startsWith' or not
        if ($startsWith && $includeDeleteAt) {
            $fileDatas = $this->blobService->getFileDataByBucketIDAndStartsWithPrefixAndIncludeDeleteAtWithPagination($internalBucketId, $prefix, $currentPageNumber, $maxNumItemsPerPage);
        } elseif ($startsWith && $includeDeleteAt === '') {
            $fileDatas = $this->blobService->getFileDataByBucketIDAndStartsWithPrefixWithPagination($internalBucketId, $prefix, $currentPageNumber, $maxNumItemsPerPage);
        } elseif (!$startsWith && $includeDeleteAt) {
            $fileDatas = $this->blobService->getFileDataByBucketIDAndPrefixAndIncludeDeleteAtWithPagination($internalBucketId, $prefix, $currentPageNumber, $maxNumItemsPerPage);
        } else {
            $fileDatas = $this->blobService->getFileDataByBucketIDAndPrefixWithPagination($internalBucketId, $prefix, $currentPageNumber, $maxNumItemsPerPage);
        }

        $bucket = $this->blobService->getConfigurationService()->getBucketByID($bucketID);

        // create sharelinks
        $validFileDatas = [];
        foreach ($fileDatas as $fileData) {
            try {
                assert($fileData instanceof FileData);

                $fileData->setBucket($bucket);
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
