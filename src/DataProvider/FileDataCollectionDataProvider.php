<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\DataProvider;

use Dbp\Relay\BlobBundle\Entity\FileData;
use Dbp\Relay\BlobBundle\Service\BlobService;
use Dbp\Relay\CoreBundle\DataProvider\AbstractDataProvider;
use Dbp\Relay\CoreBundle\Exception\ApiError;
use Symfony\Component\HttpFoundation\Response;

class FileDataCollectionDataProvider extends AbstractDataProvider
{
    /**
     * @var BlobService
     */
    private $blobService;

    public function __construct(BlobService $blobService)
    {
        $this->blobService = $blobService;
    }

    protected function getResourceClass(): string
    {
        return FileData::class;
    }

    protected function getItemById($id, array $options = []): object
    {
        // $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $fileData = $this->blobService->getFileData($id);
        $fileData = $this->blobService->setBucket($fileData);

        $fileData = $this->blobService->getLink($fileData);

        return $fileData;
    }

    protected function getPage(int $currentPageNumber, int $maxNumItemsPerPage, array $filters = [], array $options = []): array
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $bucketId = $filters['bucketID'];
        if (!$bucketId) {
            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'BucketID is no configurated', 'blob:get-files-by-prefix-unset-bucketID');
        }
        $bucket = $this->blobService->configurationService->getBucketByID($bucketId);
        if (!$bucket) {
            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'BucketID is no configurated', 'blob:get-files-by-prefix-unconfigurated-bucketID');
        }

        $prefix = $filters['prefix'];
        if (!$prefix) {
            $prefix = '';
        }

        if (!$bucket->getService()) {
            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'BucketService is no configurated', 'blob:get-files-by-prefix-no-bucket-service');
        }

        $fileDatas = $this->blobService->getFileDataByBucketIDAndPrefix($bucketId, $prefix);

        //create sharelinks
        foreach ($fileDatas as $fileData) {
            $fileData->setBucket($bucket);
            $fileData = $this->blobService->getLink($fileData);
        }

        return $fileDatas;
    }
}
