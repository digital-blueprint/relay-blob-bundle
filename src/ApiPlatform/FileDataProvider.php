<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\ApiPlatform;

use Dbp\Relay\BlobBundle\Configuration\ConfigurationService;
use Dbp\Relay\BlobBundle\Entity\FileData;
use Dbp\Relay\BlobBundle\Helper\SignatureUtils;
use Dbp\Relay\BlobBundle\Service\BlobService;
use Dbp\Relay\BlobLibrary\Api\BlobApi;
use Dbp\Relay\CoreBundle\Exception\ApiError;
use Dbp\Relay\CoreBundle\Rest\AbstractDataProvider;
use Dbp\Relay\CoreBundle\Rest\Options;
use Dbp\Relay\CoreBundle\Rest\Query\Filter\FilterTreeBuilder;
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
        private readonly ConfigurationService $config
    ) {
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
        SignatureUtils::checkSignature($errorPrefix, $this->config, $request, $filters, ['GET', 'PATCH', 'DELETE']);

        // if output validation is disabled, a user can get the data even if the system usually throws an invalid data error
        $disableOutputValidation = !$isGetRequest || ($filters['disableOutputValidation'] ?? null) === '1';
        $includeDeleteAt = ($filters['includeDeleteAt'] ?? null) === '1';
        $includeData = ($filters['includeData'] ?? null) === '1';
        $bucketIdentifier = $filters['bucketIdentifier'];

        $lock = $this->blobService->getBucketLockByInternalBucketIdAndMethod($this->blobService->getInternalBucketIdByBucketID($bucketIdentifier), $filters['method']);
        if ($lock) {
            throw ApiError::withDetails(
                Response::HTTP_FORBIDDEN,
                $filters['method'].' is locked for this bucket',
                'blob:bucket-locked'
            );
        }

        $fileData = $this->blobService->getFileData($id, [
            BlobApi::DISABLE_OUTPUT_VALIDATION_OPTION => $disableOutputValidation,
            BlobApi::INCLUDE_DELETE_AT_OPTION => $includeDeleteAt,
            BlobApi::INCLUDE_FILE_CONTENTS_OPTION => $isGetRequest && $includeData,
            BlobService::UPDATE_LAST_ACCESS_TIMESTAMP_OPTION => $isGetRequest, // PATCH: saves for itself, DELETE: will be deleted anyway
            BlobService::ASSERT_BUCKET_ID_EQUALS_OPTION => $bucketIdentifier,
            BlobService::BASE_URL_OPTION => $request->getSchemeAndHttpHost(),
        ]);

        return $fileData;
    }

    /**
     * @throws \Exception
     */
    protected function getPage(int $currentPageNumber, int $maxNumItemsPerPage, array $filters = [], array $options = []): array
    {
        SignatureUtils::checkSignature(
            'blob:get-file-data-collection',
            $this->config,
            $this->requestStack->getCurrentRequest(),
            $filters,
            ['GET']
        );

        $filterTreeBuilder = FilterTreeBuilder::create(Options::getFilter($options)?->getRootNode());

        // backwards compatibility (prefix and startsWith query parameters)
        if (($prefixFilter = $filters['prefix'] ?? '') !== '') {
            if (($filters['startsWith'] ?? null) === '1') {
                $filterTreeBuilder->iStartsWith('prefix', $prefixFilter);
            } else {
                $filterTreeBuilder->equals('prefix', $prefixFilter);
            }
        }
        $filter = $filterTreeBuilder->createFilter();

        $bucketIdentifier = $filters['bucketIdentifier'];
        $includeData = ($filters['includeData'] ?? null) === '1';
        $includeDeleteAt = ($filters['includeDeleteAt'] ?? null) === '1';
        $baseUrl = $this->requestStack->getCurrentRequest()->getSchemeAndHttpHost();

        $lock = $this->blobService->getBucketLockByInternalBucketIdAndMethod($this->blobService->getInternalBucketIdByBucketID($bucketIdentifier), $filters['method']);
        if ($lock) {
            throw ApiError::withDetails(
                Response::HTTP_FORBIDDEN,
                $filters['method'].' is locked for this bucket',
                'blob:bucket-locked'
            );
        }

        return $this->blobService->getFiles($bucketIdentifier, $filter, [
            BlobApi::INCLUDE_DELETE_AT_OPTION => $includeDeleteAt,
            BlobApi::INCLUDE_FILE_CONTENTS_OPTION => $includeData,
            BlobService::BASE_URL_OPTION => $baseUrl,
        ], $currentPageNumber, $maxNumItemsPerPage);
    }
}
