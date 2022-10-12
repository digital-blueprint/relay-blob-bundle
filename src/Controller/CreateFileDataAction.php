<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Controller;

use Dbp\Relay\BlobBundle\Entity\FileData;
use Dbp\Relay\BlobBundle\Service\BlobService;
use Dbp\Relay\CoreBundle\Exception\ApiError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class CreateFileDataAction extends BaseBlobController
{
    /**
     * @var BlobService
     */
    private $blobService;

    public function __construct(BlobService $blobService)
    {
        $this->blobService = $blobService;
    }

    /**
     * @throws HttpException
     */
    public function __invoke(Request $request): FileData
    {
        // Check bucketID
        // create id

        // check folder
        // create folder
        // rename datei
        // Uploadfile

        //check if file uploaded
        // if upload failed, figure aut why

        // create share link
        // save share link to database with valid unit date from config

        // return sharelink
        $fileData = $this->blobService->createFileData($request);

        //check bucket ID exists

        $bucket = $this->blobService->configurationService->getBucketByID($fileData->getBucketID());
        // bucket is not configured
        if (!$bucket) {
            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'BucketID is no configurated', 'blob:create-file-unconfigurated-bucketID');
        }

        //check retentionDuration & idleRetentionDuration valid durations
        if ($bucket->getMaxRetentionDuration() < $fileData->getRetentionDuration() || $fileData->getRetentionDuration() == 0) {
            $fileData->setRetentionDuration((string)$bucket->getMaxRetentionDuration());
        }

        if ($bucket->getMaxIdleRetentionDuration() < $fileData->getIdleRetentionDuration() || $fileData->getIdleRetentionDuration() == 0) {
            $fileData->setIdleRetentionDuration((string)$bucket->getMaxIdleRetentionDuration());
        }

        //TODO
        //use given service for bucket
        if (!$bucket->getService()) {
            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'BucketService is no configurated', 'blob:create-file-no-bucket-service');
        }

        $fileData->setBucket($bucket);

        //then return correct data for service
        $fileData = $this->blobService->saveFile($fileData);
        if (!$fileData) {
            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'data upload failed', 'blob:create-file-data-upload-failed');
        }

        return $fileData;
    }
}
