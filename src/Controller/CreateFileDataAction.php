<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Controller;

use Dbp\Relay\BlobBundle\Entity\FileData;
use Dbp\Relay\BlobBundle\Service\BlobService;
use Dbp\Relay\CoreBundle\Exception\ApiError;
use Symfony\Component\HttpFoundation\File\UploadedFile;
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
        // TODO replace this with signature check
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $fileData = $this->blobService->createFileData($request);

        //check bucket ID exists
        $bucket = $this->blobService->configurationService->getBucketByID($fileData->getBucketID());
        // bucket is not configured
        if (!$bucket) {
            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'BucketID is no configurated', 'blob:create-file-unconfigurated-bucketID');
        }

        //check retentionDuration & idleRetentionDuration valid durations
        if ($bucket->getMaxRetentionDuration() < $fileData->getRetentionDuration() || $fileData->getRetentionDuration() === 0) {
            $fileData->setRetentionDuration((string) $bucket->getMaxRetentionDuration());
        }

        //use given service for bucket
        if (!$bucket->getService()) {
            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'BucketService is no configurated', 'blob:create-file-no-bucket-service');
        }

        $fileData->setBucket($bucket);

        /** @var ?UploadedFile $uploadedFile */
        $uploadedFile = $fileData->getFile();
        $fileData->setExtension($uploadedFile->guessExtension());

        //then return correct data for service
        $fileData = $this->blobService->saveFile($fileData);
        if (!$fileData) {
            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'data upload failed', 'blob:create-file-data-upload-failed');
        }

        $this->blobService->saveFileData($fileData);

        return $fileData;
    }
}
