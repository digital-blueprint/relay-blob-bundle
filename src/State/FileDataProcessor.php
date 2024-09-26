<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\State;

use Dbp\Relay\BlobBundle\Entity\FileData;
use Dbp\Relay\BlobBundle\Helper\BlobUtils;
use Dbp\Relay\BlobBundle\Service\BlobService;
use Dbp\Relay\CoreBundle\Exception\ApiError;
use Dbp\Relay\CoreBundle\Rest\AbstractDataProcessor;
use JsonSchema\Constraints\Factory;
use JsonSchema\Validator;
use Symfony\Bridge\PsrHttpMessage\Factory\UploadedFile;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * @internal
 */
class FileDataProcessor extends AbstractDataProcessor
{
    public function __construct(
        private readonly BlobService $blobService,
        private readonly RequestStack $requestStack)
    {
        parent::__construct();
    }

    /**
     * NOTE: signature check is already done in the FileDataProvider, which is internally called by ApiPlatform to get the
     * FileData entity with the given identifier.
     *
     * @throws \Exception
     */
    protected function updateItem(mixed $identifier, mixed $data, mixed $previousData, array $filters): FileData
    {
        assert($data instanceof FileData);
        assert($previousData instanceof FileData);

        // important: $fileData needs to be the FileData instance retrieved by the doctrine entity manager
        // (in the FileDataProvider), otherwise later persist will fail
        $fileData = $data;
        $previousFileData = $previousData;

        $request = $this->requestStack->getCurrentRequest();

        /* get from body */
        $body = BlobUtils::getFieldsFromPatchRequest($request);
        $file = $body['file'] ?? null;
        $fileHash = $body['fileHash'] ?? null;
        $fileName = $body['fileName'] ?? null;
        $additionalMetadata = $body['metadata'] ?? null;
        $metadataHash = $body['metadataHash'] ?? null;

        /* get from url */
        $additionalType = $filters['type'] ?? null;
        $prefix = $filters['prefix'] ?? null;
        $deleteAt = $filters['deleteAt'] ?? null;
        $notifyEmail = $filters['notifyEmail'] ?? null;

        // throw error if no field is provided
        if (!$fileName && !$additionalMetadata & !$additionalType && !$prefix && !$deleteAt && !$notifyEmail && !$file) {
            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'at least one field to patch has to be provided', 'blob:patch-file-data-missing');
        }

        if ($fileName) {
            // TODO: is empty file name ('') allowed
            assert(is_string($fileName));
            $fileData->setFileName($fileName);
        }

        $bucket = $this->blobService->ensureBucket($fileData);

        if ($additionalType) {
            assert(is_string($additionalType));
            if (!array_key_exists($additionalType, $bucket->getAdditionalTypes())) {
                throw ApiError::withDetails(Response::HTTP_BAD_REQUEST,
                    'Bad type', 'blob:patch-file-data-bad-type');
            }
            $fileData->setType($additionalType);
        }

        if ($additionalMetadata) {
            if (!json_decode($additionalMetadata, true)) {
                throw ApiError::withDetails(Response::HTTP_BAD_REQUEST,
                    'Given metadata is no valid JSON!', 'blob:patch-file-data-bad-metadata');
            }

            $storedType = $fileData->getType();
            if ($storedType) {
                $schemaStorage = $this->blobService->getJsonSchemaStorageWithAllSchemasInABucket($bucket);
                $validator = new Validator(new Factory($schemaStorage));
                $metadataDecoded = json_decode($additionalMetadata);

                if (array_key_exists('type', $filters) && !empty($additionalType)
                    && $validator->validate($metadataDecoded,
                        (object) ['$ref' => 'file://'.realpath($bucket->getAdditionalTypes()[$additionalType])]) !== 0) {
                    throw ApiError::withDetails(Response::HTTP_BAD_REQUEST,
                        'Given metadata does not fit type schema!', 'blob:patch-file-data-type-mismatch');
                }
            }
            $hash = hash('sha256', $additionalMetadata);
            if ($metadataHash && $hash !== $metadataHash) {
                throw ApiError::withDetails(Response::HTTP_FORBIDDEN,
                    'Metadata hash change forbidden', 'blob:patch-file-data-metadata-hash-change-forbidden');
            }
            assert(is_string($additionalMetadata));
            $fileData->setMetadata($additionalMetadata);
            $fileData->setMetadataHash(hash('sha256', $additionalMetadata));
        }

        if ($prefix) {
            // TODO: is empty prefix ('') allowed?
            assert(is_string($prefix));
            $fileData->setPrefix($prefix);
        }

        if ($deleteAt) {
            assert(is_string($deleteAt));
            // check if date can be created
            $date = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $deleteAt);
            if ($date === false) {
                // RFC3339_EXTENDED is broken in PHP
                $date = \DateTimeImmutable::createFromFormat("Y-m-d\TH:i:s.uP", $deleteAt);
            }
            if ($date === false) {
                throw ApiError::withDetails(Response::HTTP_BAD_REQUEST,
                    'Given deleteAt is in an invalid format!', 'blob:patch-file-data-delete-at-bad-format');
            }
            $fileData->setDeleteAt($date);
        }

        if ($notifyEmail) {
            assert(is_string($notifyEmail));
            $fileData->setNotifyEmail($notifyEmail);
        }

        if ($file instanceof UploadedFile) {
            /* check hash of file */
            $hash = hash('sha256', $file->getContent());
            if ($fileHash && $hash !== $fileHash) {
                throw ApiError::withDetails(Response::HTTP_FORBIDDEN, 'File hash change forbidden',
                    'blob:patch-file-data-file-hash-change-forbidden');
            }

            $fileData->setFile($file);
            $fileData->setMimeType($file->getMimeType() ?? '');
            $fileData->setFileSize($file->getSize());
            $fileData->setFileHash(hash('sha256', $file->getContent()));
        }

        return $this->blobService->updateFile($fileData, $previousFileData);
    }

    /**
     * @throws \Exception
     */
    protected function removeItem(mixed $identifier, mixed $data, array $filters): void
    {
        // FYI: signature check is already done in the FileDataProvider, which is internally called by ApiPlatform to get the
        // FileData entity with the given identifier

        if (!Uuid::isValid($identifier)) {
            throw ApiError::withDetails(Response::HTTP_NOT_FOUND, 'Identifier is in an invalid format!', 'blob:identifier-invalid-format');
        }

        // no need to check, because signature is checked by getting the data
        assert($data instanceof FileData);

        $this->blobService->removeFile($data);
    }
}
