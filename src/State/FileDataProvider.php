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

        // if output validation is disabled, a user can get the data even if the system usually would throw and invalid data error
        $disableOutputValidation = !$isGetRequest || ($filters['disableOutputValidation'] ?? null) === '1';
        $includeFileContent = $isGetRequest && ($filters['includeData'] ?? null) === '1';
        $updateLastAccessTime = $isGetRequest; // PATCH: saves for itself, DELETE: will be deleted anyway

        $fileData = $this->blobService->getFile($id, [
            BlobService::DISABLE_OUTPUT_VALIDATION_OPTION => $disableOutputValidation,
            BlobService::INCLUDE_FILE_CONTENTS_OPTION => $includeFileContent,
            BlobService::UPDATE_LAST_ACCESS_TIMESTAMP_OPTION => $updateLastAccessTime,
        ]);

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
     * @throws \Exception
     */
    protected function getPage(int $currentPageNumber, int $maxNumItemsPerPage, array $filters = [], array $options = []): array
    {
        DenyAccessUnlessCheckSignature::checkSignature(
            'blob:get-file-data-collection', $this->blobService, $this->requestStack->getCurrentRequest(), $filters, ['GET']);

        $bucketID = rawurldecode($filters['bucketIdentifier'] ?? '');
        $prefix = rawurldecode($filters['prefix'] ?? '');
        $includeData = rawurldecode($filters['includeData'] ?? '');
        $prefixStartsWith = rawurldecode($filters['startsWith'] ?? '');
        $includeDeleteAt = rawurldecode($filters['includeDeleteAt'] ?? '');

        return $this->blobService->getFiles($bucketID, [
            BlobService::BASE_URL_OPTION => $this->requestStack->getCurrentRequest()->getSchemeAndHttpHost(),
            BlobService::INCLUDE_FILE_CONTENTS_OPTION => $includeData === '1',
            BlobService::INCLUDE_DELETE_AT_OPTION => $includeDeleteAt === '1',
            BlobService::PREFIX_OPTION => $prefix,
            BlobService::PREFIX_STARTS_WITH_OPTION => $prefixStartsWith === '1',
        ]);
    }
}
