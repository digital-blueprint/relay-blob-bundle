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
        // check minimal needed parameters for presence and correctness
        $errorPrefix = 'blob:create-file-data';
        DenyAccessUnlessCheckSignature::checkMinimalParameters($errorPrefix, $this->blobService, $request, [], ['POST']);

        // get remaining necessary params
        $bucketID = $request->query->get('bucketID', '');
        $prefix = $request->query->get('prefix', '');
        $fileName = $request->query->get('fileName', '');
        $fileHash = $request->query->get('fileHash', '');

        // get optional params
        $notifyEmail = $request->query->get('notifyEmail', '');
        $retentionDuration = $request->query->get('retentionDuration', '');
        $additionalType = $request->query->get('additionalType', '');

        // get optional additionalMetadata from formdata body
        $additionalMetadata = $request->request->get('additionalMetadata', '');

        // get request method
        $method = $request->getMethod();

        // check types of params
        assert(is_string($bucketID));
        assert(is_string($prefix));
        assert(is_string($fileName));
        assert(is_string($fileHash));
        assert(is_string($notifyEmail));
        assert(is_string($retentionDuration));
        assert(is_string($additionalType));
        assert(is_string($additionalMetadata));

        // urldecode according to RFC 3986
        $bucketID = rawurldecode($bucketID);
        $prefix = rawurldecode($prefix);
        $fileName = rawurldecode($fileName);
        $fileHash = rawurldecode($fileHash);
        $notifyEmail = rawurldecode($notifyEmail);
        $retentionDuration = rawurldecode($retentionDuration);
        $additionalType = rawurldecode($additionalType);
        $additionalMetadata = rawurldecode($additionalMetadata);

        // check if fileName is set
        if (!$fileName) {
            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'fileName is missing', 'blob:create-file-data-file-name-missing');
        }

        // check if prefix is set
        if (!$prefix) {
            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'prefix is missing', 'blob:create-file-data-prefix-missing');
        }

        // check if metadata is a valid json
        if ($additionalMetadata && !json_decode($additionalMetadata, true)) {
            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'Bad additionalMetadata', 'blob:create-file-bad-additional-metadata');
        }

        $bucket = $this->blobService->getBucketByID($bucketID);
        // check if additionaltype is defined
        if ($additionalType && !array_key_exists($additionalType, $bucket->getAdditionalTypes())) {
            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'Bad additionalType', 'blob:create-file-bad-additional-type');
        }

        // TODO implement json schema validation
        // check if given additionalMetadata json has the same keys like the defined additionalType
        if ($additionalType && $additionalMetadata && !empty(array_diff_key(json_decode($additionalMetadata, true), json_decode($bucket->getAdditionalTypes()[$additionalType], true)))) {
            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'additionalType mismatch', 'blob:create-file-additional-type-mismatch');
        }

        // get the filedata of the request
        $fileData = $this->blobService->createFileData($request);
        $fileData = $this->blobService->setBucket($fileData);

        // get bucket secret
        $bucket = $fileData->getBucket();
        $secret = $bucket->getKey();

        // check signature and checksum that is stored in signature
        DenyAccessUnlessCheckSignature::checkSignature($secret, $request, $this->blobService);

        // now, after check of signature and checksum it is safe to do computations

        // Check retentionDuration & idleRetentionDuration valid durations
        $fileData->setRetentionDuration($retentionDuration);
        if ($fileData->getRetentionDuration()) {
            $bucketExpireDate = $fileData->getDateCreated()->add(new \DateInterval($bucket->getMaxRetentionDuration()));
            $fileExpireDate = $fileData->getDateCreated()->add(new \DateInterval($fileData->getRetentionDuration()));
            if ($bucketExpireDate < $fileExpireDate) {
                $fileData->setRetentionDuration((string) $bucket->getMaxRetentionDuration());
            }
        } else {
            $fileData->setRetentionDuration((string) $bucket->getMaxRetentionDuration());
        }
        // Set exists until time
        $fileData->setExistsUntil($fileData->getDateCreated()->add(new \DateInterval($fileData->getRetentionDuration())));
        // Set everything else...
        $fileData->setFileName($fileName);
        $fileData->setNotifyEmail($notifyEmail);
        $fileData->setAdditionalType($additionalType);
        $fileData->setAdditionalMetadata($additionalMetadata);

        // Use given service for bucket
        if (!$bucket->getService()) {
            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'BucketService is not configured', 'blob:create-file-data-no-bucket-service');
        }

        /** @var ?UploadedFile $uploadedFile */
        $uploadedFile = $fileData->getFile();

        $fileData->setMimeType($uploadedFile->getMimeType() ?? '');
        $hash = hash('sha256', $uploadedFile->getContent());

        // TODO maybe save the fileHash in the database for a integrity check when retrieving files?
        // check hash of file
        if ($hash !== $fileHash) {
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
