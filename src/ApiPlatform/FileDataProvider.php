<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\ApiPlatform;

use Dbp\Relay\BlobBundle\Configuration\ConfigurationService;
use Dbp\Relay\BlobBundle\Entity\FileData;
use Dbp\Relay\BlobBundle\Helper\SignatureUtils;
use Dbp\Relay\BlobBundle\Service\BlobService;
use Dbp\Relay\CoreBundle\Exception\ApiError;
use Dbp\Relay\CoreBundle\Rest\AbstractDataProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;

/**
 * @internal
 *
 * @extends AbstractDataProvider<FileData>
 */
class FileDataProvider extends AbstractDataProvider
{
    public function __construct(
        private readonly BlobService $blobService,
        private readonly RequestStack $requestStack,
        private readonly ConfigurationService $config)
    {
        parent::__construct();
    }

    protected function requiresAuthentication(int $operation): bool
    {
        return $this->config->checkAdditionalAuth();
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
        SignatureUtils::checkSignature(
            $errorPrefix, $this->config, $request, $filters, ['GET', 'PATCH', 'DELETE']);

        // if output validation is disabled, a user can get the data even if the system usually would throw and invalid data error
        $disableOutputValidation = !$isGetRequest || ($filters['disableOutputValidation'] ?? null) === '1';
        $includeFileContent = $isGetRequest && ($filters['includeData'] ?? null) === '1';
        $bucketId = rawurldecode($filters['bucketIdentifier'] ?? '');

        $fileData = $this->blobService->getFile($id, [
            BlobService::DISABLE_OUTPUT_VALIDATION_OPTION => $disableOutputValidation,
            BlobService::INCLUDE_FILE_CONTENTS_OPTION => $includeFileContent,
            BlobService::UPDATE_LAST_ACCESS_TIMESTAMP_OPTION => $isGetRequest, // PATCH: saves for itself, DELETE: will be deleted anyway
            BlobService::BASE_URL_OPTION => $request->getSchemeAndHttpHost(),
            BlobService::ASSERT_BUCKET_ID_EQUALS_OPTION => $bucketId,
        ]);

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
        SignatureUtils::checkSignature(
            'blob:get-file-data-collection', $this->config, $this->requestStack->getCurrentRequest(), $filters, ['GET']);

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
        ], $currentPageNumber, $maxNumItemsPerPage);
    }
}
