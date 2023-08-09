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
            throw ApiError::withDetails(Response::HTTP_UNAUTHORIZED, 'Signature missing', 'blob:create-file-data-missing-sig');
        }

        // get request params
        // get necessary params
        $bucketId = $request->query->get('bucketID', '');
        $creationTime = $request->query->get('creationTime', 0);
        $prefix = $request->query->get('prefix', '');
        $urlMethod = $request->query->get('method', '');
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
        if (!$bucketId || !$creationTime || !$prefix || !$urlMethod || !$fileName) {
            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'bucketID, creationTime, prefix, method or fileName are missing and signature cannot be checked', 'blob:create-file-data-unset-params');
        }

        // check if correct method and method is specified
        if ($method !== 'POST' || $urlMethod !== 'POST') {
            throw ApiError::withDetails(Response::HTTP_METHOD_NOT_ALLOWED, 'Method and/or method not suitable', 'blob:create-file-data-method-not-suitable');
        }

        $bucket = $this->blobService->configurationService->getBucketByID($bucketId);
        if (!$bucket) {
            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'BucketID is not configured', 'blob:createFileData-bucketID-not-configured');
        }

        $linkExpiryTime = $bucket->getLinkExpireTime();
        $now = new \DateTime('now');
        $now->sub(new \DateInterval($linkExpiryTime));
        $expiryTime = strtotime($now->format('c'));

        // check if request is expired
        if ((int) $creationTime < $expiryTime) {
            throw ApiError::withDetails(Response::HTTP_FORBIDDEN, 'creationTime too old', 'blob:create-file-data-creation-time-too-old');
        }

        $fileData = $this->blobService->createFileData($request);
        $fileData = $this->blobService->setBucket($fileData);

        // get bucket secret
        $bucket = $fileData->getBucket();
        $secret = $bucket->getKey();

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
            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'BucketService is not configured', 'blob:create-file-data-no-bucket-service');
        }

        /** @var ?UploadedFile $uploadedFile */
        $uploadedFile = $fileData->getFile();
        $fileData->setExtension($uploadedFile->guessExtension() ?? substr($fileData->getFileName(), -3, 3));
        $hash = hash('sha256', $uploadedFile->getContent());

        // check hash of file
        if ($hash !== $request->query->get('fileHash', '')) {
            throw ApiError::withDetails(Response::HTTP_FORBIDDEN, 'File hash change forbidden', 'blob:create-file-data-file-hash-change-forbidden');
        }

        // Check quota
        $bucketsizeByte = (int) $this->blobService->getQuotaOfBucket($fileData->getBucketID())['bucketSize'];
        $bucketQuotaByte = $fileData->getBucket()->getQuota() * 1024 * 1024; // Convert mb to Byte
        $newBucketSizeByte = $bucketsizeByte + $fileData->getFileSize();
        if ($newBucketSizeByte > $bucketQuotaByte) {
            $this->blobService->sendNotifyQuota($bucket);
            throw ApiError::withDetails(Response::HTTP_INSUFFICIENT_STORAGE, 'Bucket quota is reached', 'blob:create-file-data-bucket-quota-reached');
        }

        // Then return correct data for service
        $fileData = $this->blobService->saveFile($fileData);
        if (!$fileData) {
            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'data upload failed', 'blob:create-file-data-data-upload-failed');
        }

        $this->blobService->saveFileData($fileData);

        return $fileData;
    }
}
