<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Api;

use Dbp\Relay\BlobBundle\Entity\FileData;
use Dbp\Relay\BlobBundle\Service\BlobService;
use Dbp\Relay\CoreBundle\Exception\ApiError;
use Symfony\Component\HttpFoundation\Response;

class FileApi
{
    public const PREFIX_STARTS_WITH_OPTION = BlobService::PREFIX_STARTS_WITH_OPTION;
    public const PREFIX_OPTION = BlobService::PREFIX_OPTION;
    public const INCLUDE_DELETE_AT_OPTION = BlobService::INCLUDE_DELETE_AT_OPTION;
    public const DISABLE_OUTPUT_VALIDATION_OPTION = BlobService::DISABLE_OUTPUT_VALIDATION_OPTION;
    public const UPDATE_LAST_ACCESS_TIMESTAMP_OPTION = BlobService::UPDATE_LAST_ACCESS_TIMESTAMP_OPTION;

    public function __construct(private readonly BlobService $blobService)
    {
    }

    /**
     * @param string|null $bucketConfigIdentifier @deprecated Set bucket ID in fileData instead
     *
     * @throws FileApiException
     */
    public function addFile(FileData $fileData, ?string $bucketConfigIdentifier = null): FileData
    {
        if ($bucketConfigIdentifier) {
            $fileData->setBucketId($bucketConfigIdentifier);
        }

        try {
            return $this->blobService->addFile($fileData);
        } catch (\Exception $exception) {
            throw $this->createException($exception);
        }
    }

    /**
     * @throws FileApiException
     */
    public function getFile(string $identifier, array $options = []): FileData
    {
        try {
            return $this->blobService->getFile($identifier, $options);
        } catch (\Exception $exception) {
            throw $this->createException($exception);
        }
    }

    /**
     * @throws FileApiException
     */
    public function getBinaryFileResponse(string $identifier): Response
    {
        try {
            return $this->blobService->getBinaryResponse($identifier, [
                BlobService::DISABLE_OUTPUT_VALIDATION_OPTION => true,
                BlobService::UPDATE_LAST_ACCESS_TIMESTAMP_OPTION => true,
            ]);
        } catch (\Exception $exception) {
            throw $this->createException($exception);
        }
    }

    /**
     * @return FileData[]
     *
     * @throws FileApiException
     */
    public function getFiles(string $bucketConfigIdentifier, array $options = [], int $currentPageNumber = 1, int $maxNumItemsPerPage = 30): array
    {
        try {
            return $this->blobService->getFiles($bucketConfigIdentifier, $options, $currentPageNumber, $maxNumItemsPerPage);
        } catch (\Exception $exception) {
            throw $this->createException($exception);
        }
    }

    /**
     * @throws FileApiException
     */
    public function updateFile(string $identifier, FileData $fileData): FileData
    {
        try {
            // TODO:
            // 1. currently fileData needs to be an instance returned by the entity manager,
            // otherwise the persist will fail. decide if this an acceptable restriction. alternatively we could
            // retrieve the file data from the entity manager, copy the contents of the updated file data and persist
            // 2. check if is save to write to fileData/previousFileData or they referencing the same instance?
            $previousFileData = $this->blobService->getFile($identifier, [
                BlobService::DISABLE_OUTPUT_VALIDATION_OPTION => true,
                BlobService::UPDATE_LAST_ACCESS_TIMESTAMP_OPTION => false,
            ]);

            return $this->blobService->updateFile($fileData, $previousFileData);
        } catch (\Exception $exception) {
            throw $this->createException($exception);
        }
    }

    /**
     * @throws FileApiException
     */
    public function removeFile(string $identifier): void
    {
        try {
            $this->blobService->removeFile($identifier);
        } catch (\Exception $exception) {
            throw $this->createException($exception);
        }
    }

    private function createException(\Exception $exception): FileApiException
    {
        if ($exception instanceof ApiError) {
            if ($exception->getStatusCode() === Response::HTTP_NOT_FOUND) {
                return new FileApiException('file not found', FileApiException::FILE_NOT_FOUND, $exception);
            }
        }

        return new FileApiException($exception->getMessage(), 0, $exception);
    }
}
