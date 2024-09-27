<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Api;

use Dbp\Relay\BlobBundle\Entity\FileData;
use Dbp\Relay\BlobBundle\Service\BlobService;
use Dbp\Relay\CoreBundle\Exception\ApiError;
use Symfony\Component\HttpFoundation\Response;

class FileApi
{
    public const DISABLE_OUTPUT_VALIDATION_OPTION = 'disable_output_validation';
    public const INCLUDE_FILE_CONTENTS_OPTION = 'include_file_contents';
    public const UPDATE_LAST_ACCESS_TIMESTAMP = 'update_last_access_timestamp';

    public function __construct(private readonly BlobService $blobService)
    {
    }

    /**
     * @throws \Exception
     */
    public function addFile(FileData $fileData): FileData
    {
        try {
            return $this->blobService->addFile($fileData);
        } catch (\Exception $exception) {
            throw $this->createException($exception);
        }
    }

    /**
     * @throws \Exception
     */
    public function getFile(string $identifier, array $options = []): FileData
    {
        try {
            return $this->blobService->getFile($identifier,
                $options[self::DISABLE_OUTPUT_VALIDATION_OPTION] ?? false,
                $options[self::INCLUDE_FILE_CONTENTS_OPTION] ?? true,
                $options[self::UPDATE_LAST_ACCESS_TIMESTAMP] ?? true);
        } catch (\Exception $exception) {
            throw $this->createException($exception);
        }
    }

    /**
     * @throws \Exception
     */
    public function updateFile(string $identifier, FileData $fileData): FileData
    {
        try {
            // TODO:
            // 1. currently fileData needs to be an instance returned by the entity manager,
            // otherwise the persist will fail. is that an acceptable restriction. alternatively we could
            // retrieve the file data from the entity manager, copy the contents of the updated file data and persist
            // 2. is it save to write to fileData/previousFileData or they referencing the same instance?
            $previousFileData = $this->blobService->getFile($identifier, true, false, false);

            return $this->blobService->updateFile($fileData, $previousFileData);
        } catch (\Exception $exception) {
            throw $this->createException($exception);
        }
    }

    /**
     * @throws \Exception
     */
    public function removeFile(string $identifier): void
    {
        try {
            $this->blobService->removeFile($identifier);
        } catch (\Exception $exception) {
            throw $this->createException($exception);
        }
    }

    private function createException(\Exception $exception): \Exception
    {
        if ($exception instanceof ApiError) {
            if ($exception->getStatusCode() === Response::HTTP_NOT_FOUND) {
                return new FileApiException('file not found', FileApiException::FILE_NOT_FOUND, $exception);
            }
        }

        return new FileApiException($exception->getMessage(), 0, $exception);
    }
}
