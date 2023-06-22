<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Controller;

use Dbp\Relay\BlobBundle\Entity\FileData;
use Dbp\Relay\BlobBundle\Helper\DenyAccessUnlessCheckSignature;
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
//        dump('CreateFileDataAction::__construct()');
    }

    /**
     * @throws HttpException
     * @throws \JsonException
     */
    public function __invoke(Request $request): FileData
    {
        /** @var string */
        $sig = $request->query->get('sig', '');
        // check if signature is present in url
        if (!$sig) {
            throw ApiError::withDetails(Response::HTTP_UNAUTHORIZED, 'Signature missing', 'blob:createFileData-missing-sig');
        }

        // get request params
        // get necessary params
        $bucketId = $request->query->get('bucketID', '');
        $creationTime = $request->query->get('creationTime', 0);
        $prefix = $request->query->get('prefix', '');
        $action = $request->query->get('action', '');
        /** @var string */
        $fileName = $request->query->get('fileName', '');

        // get optional params
        $fileHash = $request->query->get('fileHash', '');
        /** @var string */
        $notifyEmail = $request->query->get('notifyEmail', '');
        $retentionDuration = $request->query->get('retentionDuration', '');
        $additionalMetadata = $request->query->get('additionalMetadata', '');

        // get request method
        $method = $request->getMethod();

        // check types of params
        assert(is_string($bucketId));
        assert(is_string($prefix));
        assert(is_string($fileName));
        assert(is_string($notifyEmail));
        assert(is_string($sig));

        // check if the minimal needed url params are present
        if (!$bucketId || !$creationTime || !$prefix || !$action || !$fileName) {
            throw ApiError::withDetails(Response::HTTP_FORBIDDEN, 'Signature cannot be checked', 'blob:createFileData-unset-sig-params');
        }

        // check if correct method and action is specified
        if ($method !== 'POST' || $action !== 'CREATEONE') {
            throw ApiError::withDetails(Response::HTTP_FORBIDDEN, 'Signature not suitable', 'blob:dataprovider-signature-not-suitable');
        }

        // check if request is expired
        if ((int) $creationTime < $tooOld = strtotime('-5 min')) {
            throw ApiError::withDetails(Response::HTTP_FORBIDDEN, 'Creation Time too old', 'blob:creationtime-too-old');
        }

        $fileData = $this->blobService->createFileData($request);
        $fileData = $this->blobService->setBucket($fileData);

        // get bucket secret
        $bucket = $fileData->getBucket();
        $secret = $bucket->getPublicKey();

        // check signature and checksum that is stored in signature
        DenyAccessUnlessCheckSignature::verifyChecksumAndSignature($secret, $sig, $request);

        // now, after check of signature and checksum it is safe to do computations

        // Check retentionDuration & idleRetentionDuration valid durations
        $fileData->setRetentionDuration($retentionDuration);
        if ($bucket->getMaxRetentionDuration() < $fileData->getRetentionDuration() || !$fileData->getRetentionDuration()) {
            $fileData->setRetentionDuration((string) $bucket->getMaxRetentionDuration());
        }
        // Set exists until time
        $fileData->setExistsUntil($fileData->getDateCreated()->add(new \DateInterval($fileData->getRetentionDuration())));
        // Set everything else...
        $fileData->setFileName($fileName);
        $fileData->setNotifyEmail($notifyEmail);
        $fileData->setAdditionalMetadata($additionalMetadata);

        // Use given service for bucket
        if (!$bucket->getService()) {
            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'BucketService is not configured', 'blob:create-file-no-bucket-service');
        }

        /** @var ?UploadedFile $uploadedFile */
        $uploadedFile = $fileData->getFile();
        $fileData->setExtension($uploadedFile->guessExtension() ?? substr($fileData->getFileName(), -3, 3));
        $hash = hash('sha256', $uploadedFile->getContent());

        // check hash of file
        if ($hash !== $request->query->get('fileHash', '')) {
            throw ApiError::withDetails(Response::HTTP_FORBIDDEN, 'File hash change forbidden', 'blob:file-hash-change-forbidden');
        }

        // Check quota
        $bucketsizeByte = (int) $this->blobService->getQuotaOfBucket($fileData->getBucketID())['bucketSize'];
        $bucketQuotaByte = $fileData->getBucket()->getQuota() * 1024 * 1024; // Convert mb to Byte
        $newBucketSizeByte = $bucketsizeByte + $fileData->getFileSize();
        if ($newBucketSizeByte > $bucketQuotaByte) {
            $this->blobService->sendNotifyQuota($bucket);
            throw ApiError::withDetails(Response::HTTP_INSUFFICIENT_STORAGE, 'Bucket quota is reached', 'blob:create-file-bucket-quota-reached');
        }

        // Then return correct data for service
        $fileData = $this->blobService->saveFile($fileData);
        if (!$fileData) {
            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'data upload failed', 'blob:create-file-data-upload-failed');
        }

        $this->blobService->saveFileData($fileData);

        return $fileData;
    }
}
