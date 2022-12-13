<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\DataProvider;

use Dbp\Relay\BlobBundle\Entity\FileData;
use Dbp\Relay\BlobBundle\Helper\DenyAccessUnlessCheckSignature;
use Dbp\Relay\BlobBundle\Service\BlobService;
use Dbp\Relay\CoreBundle\DataProvider\AbstractDataProvider;
use Dbp\Relay\CoreBundle\Exception\ApiError;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;

class FileDataDataProvider extends AbstractDataProvider
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
        parent::__construct($requestStack);

        $this->blobService = $blobService;
        $this->requestStack = $requestStack;
    }

    protected function getResourceClass(): string
    {
        return FileData::class;
    }

    /* getItemById() is overwritten with getFileDataById() because we want filters here for checking the signature */
    public function getItem(string $resourceClass, $id, string $operationName = null, array $context = []): ?object
    {
        $this->onOperationStart(self::GET_ITEM_OPERATION);

        $filters = $context['filters'] ?? [];

        return $this->getFileDataById($id, $filters);
    }

    protected function getFileDataById($id, array $filters): object
    {
        $this->checkSignature($filters);

        $fileData = $this->blobService->getFileData($id);

        if (!$fileData) {
            throw ApiError::withDetails(Response::HTTP_NOT_FOUND, 'FileData was not found!', 'blob:fileData-not-found');
        }

        $fileData = $this->blobService->setBucket($fileData);
        $fileData = $this->blobService->getLink($fileData);
        $this->blobService->saveFileData($fileData);

        return $fileData;
    }

    protected function getItemById($id, array $options = []): object
    {
        throw ApiError::withDetails(Response::HTTP_NOT_FOUND, 'Should not be called!', 'blob:wrong-function');
    }

    protected function getPage(int $currentPageNumber, int $maxNumItemsPerPage, array $filters = [], array $options = []): array
    {
        $this->checkSignature($filters);

        $bucketId = $filters['bucketID'];
        if (!$bucketId) {
            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'BucketID is not configured', 'blob:get-files-by-prefix-unset-bucketID');
        }
        $bucket = $this->blobService->configurationService->getBucketByID($bucketId);
        if (!$bucket) {
            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'BucketID is not configured', 'blob:get-files-by-prefix-not-configured-bucketID');
        }

        $prefix = $filters['prefix'] ?? '';

        if (!$bucket->getService()) {
            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'BucketService is not configured', 'blob:get-files-by-prefix-no-bucket-service');
        }

        $fileDatas = $this->blobService->getFileDataByBucketIDAndPrefixWithPagination($bucketId, $prefix, $currentPageNumber, $maxNumItemsPerPage);

        //create sharelinks
        foreach ($fileDatas as $fileData) {
            $fileData->setBucket($bucket);
            $fileData = $this->blobService->getLink($fileData);
            $this->blobService->saveFileData($fileData);
        }

        return $fileDatas;
    }

    private function checkSignature($filters): void
    {
        $sig = $this->requestStack->getCurrentRequest()->headers->get('x-dbp-signature', '');
        $uri = $this->requestStack->getCurrentRequest()->getUri();

        if (!$uri || !$sig || !array_key_exists('bucketID', $filters) || !array_key_exists('creationTime', $filters)) {
            throw ApiError::withDetails(Response::HTTP_FORBIDDEN, 'Signature cannot checked', 'blob:dataprovider-unset-sig-params');
        }

        DenyAccessUnlessCheckSignature::denyAccessUnlessSiganture($filters['bucketID'], $filters['creationTime'], $uri, $sig);
    }
}
