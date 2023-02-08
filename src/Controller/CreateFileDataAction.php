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
//        dump('CreateFileDataAction::invoke()');
        $sig = $request->headers->get('x-dbp-signature', '');
        if (!$sig) {
            throw ApiError::withDetails(Response::HTTP_UNAUTHORIZED, 'Signature missing', 'blob:createFileData-missing-sig');
        }
        $bucketId = $request->query->get('bucketID', '');
        assert(is_string($bucketId));
        $creationTime = $request->query->get('creationTime', 0);
        $prefix = $request->query->get('prefix', '');
        assert(is_string($prefix));

        if (!$bucketId || !$creationTime) {
            throw ApiError::withDetails(Response::HTTP_FORBIDDEN, 'Signature cannot checked', 'blob:createFileData-unset-sig-params');
        }

        $fileData = $this->blobService->createFileData($request);
        $fileData = $this->blobService->setBucket($fileData);

        $bucket = $fileData->getBucket();
        $secret = $bucket->getPublicKey();

        $data = DenyAccessUnlessCheckSignature::verify($secret, $sig);
//        dump($data);

        // check if signed params aer equal to request params
        if ($data['bucketID'] !== $bucketId) {
            /* @noinspection ForgottenDebugOutputInspection */
            dump($data['bucketID'], $bucketId);
            throw ApiError::withDetails(Response::HTTP_FORBIDDEN, 'BucketId change forbidden', 'blob:bucketid-change-forbidden');
        }
        if ((int) $data['creationTime'] !== (int) $creationTime) {
            /* @noinspection ForgottenDebugOutputInspection */
            dump($data['creationTime'], $creationTime);
            throw ApiError::withDetails(Response::HTTP_FORBIDDEN, 'Creation Time change forbidden', 'blob:creationtime-change-forbidden');
        }
        if ($data['prefix'] !== $prefix) {
            /* @noinspection ForgottenDebugOutputInspection */
            dump($data['prefix'], $prefix);
            throw ApiError::withDetails(Response::HTTP_FORBIDDEN, 'Prefix change forbidden', 'blob:prefix-change-forbidden');
        }
        // check if request is expired
        if ((int) $data['creationTime'] < $tooOld = strtotime('-5 min')) {
            /* @noinspection ForgottenDebugOutputInspection */
            dump((int) $data['creationTime'], $tooOld);
            throw ApiError::withDetails(Response::HTTP_FORBIDDEN, 'Creation Time too old', 'blob:creationtime-too-old');
        }
        // check action/method
        $method = $request->getMethod();
        $action = $data['action'] ?? '';
        echo "    CreateFileDataAction::__invoke(): method=$method, action=$action\n";
        if ($method !== 'POST' || $action !== 'CREATEONE') {
            throw ApiError::withDetails(Response::HTTP_FORBIDDEN, 'Signature not suitable', 'blob:dataprovider-signature-not-suitable');
        }

        // Check retentionDuration & idleRetentionDuration valid durations
        $fileData->setRetentionDuration($data['retentionDuration'] ?? '');
        if ($bucket->getMaxRetentionDuration() < $fileData->getRetentionDuration() || !$fileData->getRetentionDuration()) {
            $fileData->setRetentionDuration((string) $bucket->getMaxRetentionDuration());
        }

        // Set exists until time
        $fileData->setExistsUntil($fileData->getDateCreated()->add(new \DateInterval($fileData->getRetentionDuration())));
        // Set everything else...
        $fileData->setFileName($data['fileName']);
        $fileData->setNotifyEmail($data['notifyEmail'] ?? '');
        $fileData->setAdditionalMetadata($data['additionalMetadata'] ?? '');

        // Use given service for bucket
        if (!$bucket->getService()) {
            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'BucketService is not configured', 'blob:create-file-no-bucket-service');
        }

        /** @var ?UploadedFile $uploadedFile */
        $uploadedFile = $fileData->getFile();
        $fileData->setExtension($uploadedFile->guessExtension() ?? substr($fileData->getFileName(), -3, 3));
        $hash = hash('sha256', $uploadedFile->getContent());

        // check hash of file
        if ($hash !== $data['fileHash']) {
            /** @noinspection ForgottenDebugOutputInspection */
            dump($data['fileHash'], $hash);
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
