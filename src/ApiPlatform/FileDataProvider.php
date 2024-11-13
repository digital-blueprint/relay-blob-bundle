<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\ApiPlatform;

use Dbp\Relay\BlobBundle\Configuration\ConfigurationService;
use Dbp\Relay\BlobBundle\Entity\FileData;
use Dbp\Relay\BlobBundle\Helper\SignatureUtils;
use Dbp\Relay\BlobBundle\Service\BlobService;
use Dbp\Relay\CoreBundle\Exception\ApiError;
use Dbp\Relay\CoreBundle\Rest\AbstractDataProvider;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;

/**
 * @internal
 *
 * @extends AbstractDataProvider<FileData>
 */
class FileDataProvider extends AbstractDataProvider implements LoggerAwareInterface
{
    use LoggerAwareTrait;

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
        $includeDeleteAt = ($filters['includeDeleteAt'] ?? null) === '1';
        $includeData = ($filters['includeData'] ?? null) === '1';
        $bucketId = rawurldecode($filters['bucketIdentifier'] ?? '');

        $fileData = $this->blobService->getFile($id, [
            BlobService::DISABLE_OUTPUT_VALIDATION_OPTION => $disableOutputValidation,
            BlobService::INCLUDE_DELETE_AT_OPTION => $includeDeleteAt,
            BlobService::UPDATE_LAST_ACCESS_TIMESTAMP_OPTION => $isGetRequest, // PATCH: saves for itself, DELETE: will be deleted anyway
            BlobService::ASSERT_BUCKET_ID_EQUALS_OPTION => $bucketId,
        ]);

        $fileData->setContentUrl($isGetRequest && $includeData ?
            $this->blobService->getContentUrl($fileData) :
            $this->blobService->getDownloadUrl($request->getSchemeAndHttpHost(), $fileData));

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
        $includeData = ($filters['includeData'] ?? null) === '1';
        $prefixStartsWith = rawurldecode($filters['startsWith'] ?? '');
        $includeDeleteAt = rawurldecode($filters['includeDeleteAt'] ?? '');
        $request = $this->requestStack->getCurrentRequest();

        $validFileDataCollection = [];
        foreach ($this->blobService->getFiles($bucketID, [
            BlobService::INCLUDE_DELETE_AT_OPTION => $includeDeleteAt === '1',
            BlobService::PREFIX_OPTION => $prefix,
            BlobService::PREFIX_STARTS_WITH_OPTION => $prefixStartsWith === '1',
        ], $currentPageNumber, $maxNumItemsPerPage) as $fileData) {
            try {
                $fileData->setContentUrl($includeData ?
                    $this->blobService->getContentUrl($fileData) :
                    $this->blobService->getDownloadUrl($request->getSchemeAndHttpHost(), $fileData));
                $validFileDataCollection[] = $fileData;
            } catch (ApiError $apiError) {
                // skip file not found
                if ($apiError->getStatusCode() === Response::HTTP_NOT_FOUND) {
                    // TODO how to handle this correctly? This should never happen in the first place
                    $this->logger->error(sprintf(__FILE__.':'.__LINE__.': failed to get file content for file ID "%s"', $fileData->getIdentifier()));
                    continue;
                }
            }
        }

        return $validFileDataCollection;
    }
}
