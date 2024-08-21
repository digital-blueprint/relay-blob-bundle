<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Controller;

use Dbp\Relay\BlobBundle\Entity\FileData;
use Dbp\Relay\BlobBundle\Event\AddFileDataByPostSuccessEvent;
use Dbp\Relay\BlobBundle\Helper\BlobUtils;
use Dbp\Relay\BlobBundle\Helper\DenyAccessUnlessCheckSignature;
use Dbp\Relay\BlobBundle\Service\BlobService;
use Dbp\Relay\CoreBundle\Exception\ApiError;
use JsonSchema\Constraints\Factory;
use JsonSchema\Validator;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class CreateFileDataAction extends BaseBlobController
{
    private BlobService $blobService;

    private EventDispatcherInterface $eventDispatcher;

    public function __construct(BlobService $blobService, EventDispatcherInterface $eventDispatcher)
    {
        $this->blobService = $blobService;
        $this->eventDispatcher = $eventDispatcher;
    }

    /**
     * @throws HttpException
     * @throws \JsonException
     * @throws \Exception
     */
    public function __invoke(Request $request): FileData
    {
        /* check minimal needed parameters for presence and correctness */
        $errorPrefix = 'blob:create-file-data';
        DenyAccessUnlessCheckSignature::checkMinimalParameters($errorPrefix, $this->blobService, $request, [], ['POST']);

        /* get url params */
        $bucketID = $request->query->get('bucketIdentifier', '');
        $notifyEmail = $request->query->get('notifyEmail', '');
        $prefix = $request->query->get('prefix', '');
        $retentionDuration = $request->query->get('retentionDuration', '');
        $additionalType = $request->query->get('type', '');

        /* get params from body */
        $additionalMetadata = $request->request->get('metadata', '');
        $metadataHash = $request->request->get('metadataHash', '');
        $fileName = $request->request->get('fileName', '');
        $fileHash = $request->request->get('fileHash', '');

        /* check types of params */
        assert(is_string($bucketID));
        assert(is_string($prefix));
        assert(is_string($fileName));
        assert(is_string($fileHash));
        assert(is_string($notifyEmail));
        assert(is_string($retentionDuration));
        assert(is_string($additionalType));
        assert(is_string($additionalMetadata));

        /* urldecode according to RFC 3986 */
        $bucketID = rawurldecode($bucketID);
        $prefix = rawurldecode($prefix);
        $notifyEmail = rawurldecode($notifyEmail);
        $retentionDuration = rawurldecode($retentionDuration);
        $additionalType = rawurldecode($additionalType);

        // check content-length header to prevent misleading error messages if the upload is too big for the server to accept
        if ($request->headers->get('Content-Length') && intval($request->headers->get('Content-Length')) !== 0 && intval($request->headers->get('Content-Length')) > BlobUtils::convertFileSizeStringToBytes(ini_get('post_max_size'))) {
            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'Given file is too large', 'blob:create-file-data-file-too-big');
        }

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
            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'Bad metadata', 'blob:create-file-data-bad-metadata');
        }

        // get current bucket
        $bucket = $this->blobService->getBucketByID($bucketID);

        // check if additionaltype is defined
        if ($additionalType && !array_key_exists($additionalType, $bucket->getAdditionalTypes())) {
            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'Bad type', 'blob:create-file-data-bad-type');
        }

        if ($additionalType) {
            /* check if given metadata json has the same keys like the defined type */
            $schemaStorage = $this->blobService->getJsonSchemaStorageWithAllSchemasInABucket($bucket);
            $jsonSchemaObject = json_decode(file_get_contents($bucket->getAdditionalTypes()[$additionalType]));

            $validator = new Validator(new Factory($schemaStorage));
            $metadataDecoded = (object) json_decode($additionalMetadata);
            if ($additionalType && $additionalMetadata && $validator->validate($metadataDecoded, $jsonSchemaObject) !== 0) {
                $messages = [];
                foreach ($validator->getErrors() as $error) {
                    $messages[$error['property']] = $error['message'];
                }
                throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'metadata does not match specified type', 'blob:create-file-data-metadata-does-not-match-type', $messages);
            }
        }

        // get the filedata of the request
        $fileData = $this->blobService->createFileData($request);
        $fileData = $this->blobService->setBucket($fileData);

        // get bucket secret
        $bucket = $fileData->getBucket();
        $secret = $bucket->getKey();

        // check signature and checksum that is stored in signature
        DenyAccessUnlessCheckSignature::checkSignature($secret, $request, $this->blobService, $this->isGranted('IS_AUTHENTICATED_FULLY'), $this->blobService->checkAdditionalAuth());

        /* now, after check of signature and checksum it is safe to do computations */

        /* Check retentionDuration & idleRetentionDuration valid durations */
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

        if ($fileData->getRetentionDuration() !== $this->blobService->getDefaultRetentionDurationByBucketId($bucketID)) {
            $fileData->setExistsUntil($fileData->getDateCreated()->add(new \DateInterval($fileData->getRetentionDuration())));
        } else {
            $fileData->setExistsUntil(null);
        }

        /* set given and calculated values */
        $fileData->setFileName($fileName);
        $fileData->setNotifyEmail($notifyEmail);
        $fileData->setType($additionalType);
        $fileData->setMetadata($additionalMetadata);

        // Use given service for bucket
        if (!$bucket->getService()) {
            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'BucketService is not configured', 'blob:create-file-data-no-bucket-service');
        }

        /** @var ?UploadedFile $uploadedFile */
        $uploadedFile = $fileData->getFile();

        $fileData->setMimeType($uploadedFile->getMimeType() ?? '');

        /* check hashes of file and metadata */
        // check hash of file
        $hash = hash('sha256', $uploadedFile->getContent());
        if ($fileHash && $hash !== $fileHash) {
            throw ApiError::withDetails(Response::HTTP_FORBIDDEN, 'File hash change forbidden', 'blob:create-file-data-file-hash-change-forbidden');
        }
        // check hash of metadata
        $hash = hash('sha256', $additionalMetadata);
        if ($metadataHash && $hash !== $metadataHash) {
            throw ApiError::withDetails(Response::HTTP_FORBIDDEN, 'Metadata hash change forbidden', 'blob:create-file-data-metadata-hash-change-forbidden');
        }

        /* Check quota */
        $bucketSize = $this->blobService->getCurrentBucketSize($fileData->getInternalBucketID());
        if ($bucketSize !== null) {
            $bucketsizeByte = (int) $bucketSize['bucketSize'];
        } else {
            $bucketsizeByte = 0;
        }
        $bucketQuotaByte = $fileData->getBucket()->getQuota() * 1024 * 1024; // Convert mb to Byte
        $newBucketSizeByte = $bucketsizeByte + $fileData->getFileSize();
        if ($newBucketSizeByte > $bucketQuotaByte) {
            throw ApiError::withDetails(Response::HTTP_INSUFFICIENT_STORAGE, 'Bucket quota is reached', 'blob:create-file-data-bucket-quota-reached');
        }

        // write all relevant data to tables
        $this->blobService->writeToTablesAndSaveFileData($fileData, $newBucketSizeByte);

        /* dispatch POST success event */
        $successEvent = new AddFileDataByPostSuccessEvent($fileData);
        $this->eventDispatcher->dispatch($successEvent);

        return $fileData;
    }
}
