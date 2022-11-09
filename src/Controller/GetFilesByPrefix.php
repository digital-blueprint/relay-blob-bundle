<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Controller;

use Dbp\Relay\BlobBundle\Service\BlobService;
use Dbp\Relay\CoreBundle\Exception\ApiError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class GetFilesByPrefix extends BaseBlobController
{
    /**
     * @var BlobService
     */
    private $blobService;

    public function __construct(BlobService $blobService)
    {
        $this->blobService = $blobService;
    }

    public function __invoke(Request $request): array
    {
        $bucketId = (string) $request->query->get('bucketID');
        if (!$bucketId) {
            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'BucketID is no configurated', 'blob:get-files-by-prefix-unset-bucketID');
        }
        $bucket = $this->blobService->configurationService->getBucketByID($bucketId);
        if (!$bucket) {
            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'BucketID is no configurated', 'blob:get-files-by-prefix-unconfigurated-bucketID');
        }

        $prefix = $request->query->get('prefix');
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
            $fileData = $this->blobService->getLink($fileData, $bucket);
        }

        return $fileDatas;
    }
}
