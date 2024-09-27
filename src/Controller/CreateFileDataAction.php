<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Controller;

use Dbp\Relay\BlobBundle\Entity\FileData;
use Dbp\Relay\BlobBundle\Helper\BlobUtils;
use Dbp\Relay\BlobBundle\Helper\DenyAccessUnlessCheckSignature;
use Dbp\Relay\BlobBundle\Service\BlobService;
use Dbp\Relay\CoreBundle\Exception\ApiError;
use Dbp\Relay\CoreBundle\Rest\CustomControllerTrait;
use JsonSchema\Constraints\Factory;
use JsonSchema\Validator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class CreateFileDataAction extends AbstractController
{
    use CustomControllerTrait;

    public function __construct(private readonly BlobService $blobService)
    {
    }

    /**
     * @throws HttpException
     * @throws \JsonException
     * @throws \Exception
     */
    public function __invoke(Request $request): FileData
    {
        if ($this->blobService->getAdditionalAuthFromConfig()) {
            $this->requireAuthentication();
        }

        /* check minimal needed parameters for presence and correctness */
        $errorPrefix = 'blob:create-file-data';
        DenyAccessUnlessCheckSignature::checkSignature(
            $errorPrefix, $this->blobService, $request, $request->query->all(), ['POST']);

        /* get url params */
        $bucketID = $request->query->get('bucketIdentifier', '');
        $notifyEmail = $request->query->get('notifyEmail', null);
        $prefix = $request->query->get('prefix', '');
        $retentionDuration = $request->query->get('retentionDuration', null);
        $additionalType = $request->query->get('type', null);

        /* get params from body */
        $additionalMetadata = $request->request->get('metadata', '');
        $metadataHash = $request->request->get('metadataHash', null);
        $fileName = $request->request->get('fileName', '');
        $fileHash = $request->request->get('fileHash', null);

        /* check types of params */
        assert(is_string($bucketID));
        assert(is_string($prefix));
        assert(is_string($fileName));
        assert(is_string($fileHash ?? ''));
        assert(is_string($notifyEmail ?? ''));
        assert(is_string($retentionDuration ?? ''));
        assert(is_string($additionalType ?? ''));
        assert(is_string($additionalMetadata));

        /* urldecode according to RFC 3986 */
        $bucketID = rawurldecode($bucketID);
        $prefix = rawurldecode($prefix);
        $notifyEmail = $notifyEmail ? rawurldecode($notifyEmail) : null;
        $retentionDuration = $retentionDuration ? rawurldecode($retentionDuration) : null;
        $additionalType = $additionalType ? rawurldecode($additionalType) : null;

        // check content-length header to prevent misleading error messages if the upload is too big for the server to accept
        if ($request->headers->get('Content-Length') && intval($request->headers->get('Content-Length')) !== 0
            && intval($request->headers->get('Content-Length')) > BlobUtils::convertFileSizeStringToBytes(ini_get('post_max_size'))) {
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

        // check if additional type is defined
        if ($additionalType && !array_key_exists($additionalType, $bucket->getAdditionalTypes())) {
            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'Bad type', 'blob:create-file-data-bad-type');
        }

        if ($additionalType) {
            /* check if given metadata json has the same keys as the defined type */
            $schemaStorage = $this->blobService->getJsonSchemaStorageWithAllSchemasInABucket($bucket);
            $jsonSchemaObject = json_decode(file_get_contents($bucket->getAdditionalTypes()[$additionalType]));

            $validator = new Validator(new Factory($schemaStorage));
            $metadataDecoded = (object) json_decode($additionalMetadata);
            if ($validator->validate($metadataDecoded, $jsonSchemaObject) !== 0) {
                $messages = [];
                foreach ($validator->getErrors() as $error) {
                    $messages[$error['property']] = $error['message'];
                }
                throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'metadata does not match specified type', 'blob:create-file-data-metadata-does-not-match-type', $messages);
            }
        }

        // TODO: centralize setup of FileData either here or at BlobService::createFileData
        $fileData = $this->blobService->createFileData($request);
        $fileData->setBucket($bucket);

        /* now, after check of signature and checksum it is safe to do computations */
        $fileData->setRetentionDuration($retentionDuration);
        if ($fileData->getRetentionDuration() !== null) {
            $fileData->setDeleteAt($fileData->getDateCreated()->add(new \DateInterval($fileData->getRetentionDuration())));
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

        return $this->blobService->addFile($fileData);
    }
}
