<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Controller;

use Dbp\Relay\BlobBundle\Entity\FileData;
use Dbp\Relay\BlobBundle\Service\BlobService;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Dbp\Relay\CoreBundle\Exception\ApiError;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

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
        $contentUrl = 'my-url';
        $fileDataIdentifier = '1234';
        $fileData = new FileData();

        $fileData->setIdentifier((string) Uuid::v4());

        /** @var ?UploadedFile $uploadedFile */
        $uploadedFile = $request->files->get('file');

        // check if there is an uploaded file
        if (!$uploadedFile) {
            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'No file with parameter key "file" was received!', 'blob:create-file-missing-file');
        }

        // If the upload failed, figure out why
        if ($uploadedFile->getError() !== UPLOAD_ERR_OK) {
            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, $uploadedFile->getErrorMessage(), 'blob:create-file-upload-error');
        }

        // check if file is empty
        if ($uploadedFile->getSize() === 0) {
            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'Empty files cannot be added!', 'blob:create-file-empty-files-not-allowed');
        }

        $fileData->setFile($uploadedFile);


        $fileData->setPrefix($request->get('prefix'));
        $fileData->setFileName($request->get('fileName'));
        $time = new \DateTime('now');
        $fileData->setDateCreated($time);
        $fileData->setLastAccess($time);
        $fileData->setAdditionalMetadata($request->get('additionalMetadata'));

        $fileData->setBucketID($request->get('bucketID'));
        $fileData->setRetentionDuration($request->get('retentionDuration'));
        $fileData->setIdleRetentionDuration($request->get('idleRetentionDuration'));

        $config = $this->blobService->configurationService->getBucketByID($fileData->getBucketID());
        // bucket is not configured
        if (!$config) {
            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'BucketID is no configurated', 'blob:create-file-unconfigurated-bucketID');
        }

        //TODO
        //check config
        //use given service for bucket
        //then return correct data for service



        return $this->blobService->createFileData($fileData, $fileDataIdentifier, $contentUrl);
    }
}
