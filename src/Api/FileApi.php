<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Api;

use Dbp\Relay\BlobBundle\Entity\FileData;
use Dbp\Relay\BlobBundle\Service\BlobService;
use Dbp\Relay\BlobLibrary\Api\BlobApi;
use Dbp\Relay\BlobLibrary\Api\BlobApiError;
use Dbp\Relay\BlobLibrary\Api\BlobFile;
use Dbp\Relay\BlobLibrary\Api\BlobFileApiInterface;
use Dbp\Relay\CoreBundle\Exception\ApiError;
use Dbp\Relay\CoreBundle\Rest\Query\Filter\FilterTreeBuilder;
use Psr\Http\Message\StreamInterface;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;

class FileApi implements BlobFileApiInterface
{
    private ?string $bucketIdentifier = null;

    private static function generateTempFilePath(): string|false
    {
        return tempnam(sys_get_temp_dir(), 'dbp_relay_blob_bundle_tempfile_');
    }

    /**
     * @throws BlobApiError
     */
    private static function createOrUpdateFileDataFromBlobFile(BlobFile $blobFile, ?FileData $fileData = null): FileData
    {
        $fileData ??= new FileData();
        if ($blobFile->getIdentifier()) {
            $fileData->setIdentifier($blobFile->getIdentifier());
        }
        if ($blobFile->getPrefix()) {
            $fileData->setPrefix($blobFile->getPrefix());
        }
        if ($blobFile->getFileName()) {
            $fileData->setFileName($blobFile->getFileName());
        }
        if ($mimeType = $blobFile->getMimeType()) {
            $fileData->setMimeType($mimeType);
        }
        if ($type = $blobFile->getType()) {
            $fileData->setType($type);
        }
        if ($metadata = $blobFile->getMetadata()) {
            $fileData->setMetadata($metadata);
        }
        if ($file = $blobFile->getFile()) {
            if ($file instanceof File) {
                $symfonyFile = $file;
            } else {
                if ($file instanceof \SplFileInfo) {
                    $tempFilePath = $file->getPathname();
                } elseif (is_string($file)) {
                    $tempFilePath = self::generateTempFilePath();
                    file_put_contents($tempFilePath, $file);
                } elseif ($file instanceof StreamInterface) {
                    $tempFilePath = self::generateTempFilePath();
                    $tempFile = fopen($tempFilePath, 'w');
                    while (false === $file->eof()) {
                        $chunk = $file->read(1024);
                        fwrite($tempFile, $chunk);
                    }
                    fclose($tempFile);
                } elseif (is_resource($file)) {
                    $tempFilePath = self::generateTempFilePath();
                    $tempFile = fopen($tempFilePath, 'w');
                    while (!feof($file)) {
                        $chunk = fread($file, 1024);
                        fwrite($tempFile, $chunk);
                    }
                    fclose($tempFile);
                    fclose($file);
                } else {
                    throw new BlobApiError('unsupported file object', BlobApiError::REQUIRED_PARAMETER_MISSING);
                }
                $symfonyFile = new File($tempFilePath);
            }
            $fileData->setFile($symfonyFile);
        }

        return $fileData;
    }

    private static function createBlobFileFromFileData(FileData $fileData): BlobFile
    {
        return new BlobFile(json_decode(json_encode($fileData), true));
    }

    public function __construct(
        private readonly BlobService $blobService,
        private readonly RequestStack $requestStack)
    {
    }

    public function setBucketIdentifier(string $bucketIdentifier): void
    {
        $this->bucketIdentifier = $bucketIdentifier;
    }

    /**
     * @throws BlobApiError
     */
    public function addFile(BlobFile $blobFile, array $options = []): BlobFile
    {
        $fileData = self::createOrUpdateFileDataFromBlobFile($blobFile);
        $fileData->setBucketId($this->bucketIdentifier);

        if ($blobBaseUrl = $this->tryGetBlobBaseUrlFromCurrentRequest()) {
            $options[BlobService::BASE_URL_OPTION] = $blobBaseUrl;
        }

        try {
            // base-URL option? is contentUrl returned on POST?
            return self::createBlobFileFromFileData($this->blobService->addFile($fileData, $options));
        } catch (\Exception $exception) {
            throw $this->createBlobApiError($exception, 'Adding file failed');
        }
    }

    /**
     * @throws BlobApiError
     */
    public function updateFile(BlobFile $blobFile, array $options = []): BlobFile
    {
        try {
            $options[BlobService::UPDATE_LAST_ACCESS_TIMESTAMP_OPTION] = false;
            $options[BlobApi::DISABLE_OUTPUT_VALIDATION_OPTION] = true;

            $fileData = $this->getFileData($blobFile->getIdentifier(), $options);
            $previousFileData = clone $fileData;

            $fileData = self::createOrUpdateFileDataFromBlobFile($blobFile, $fileData);

            return self::createBlobFileFromFileData(
                $this->blobService->updateFile($fileData, $previousFileData)
            );
        } catch (\Exception $exception) {
            throw $this->createBlobApiError($exception, 'Updating file failed');
        }
    }

    /**
     * @throws BlobApiError
     */
    public function removeFile(string $identifier, array $options = []): void
    {
        try {
            $this->blobService->removeFile($identifier);
        } catch (\Exception $exception) {
            throw $this->createBlobApiError($exception, 'Removing file failed');
        }
    }

    /**
     * @throws BlobApiError
     */
    public function removeFiles(array $options = []): void
    {
        // TODO: batch remove?
        $currentPage = 1;
        $maxNumItemsPerPage = 100;
        $fileDataCollection = [];

        try {
            do {
                $filePage = $this->getFiles($currentPage, $maxNumItemsPerPage, $options);
                array_push($fileDataCollection, ...$filePage);
                ++$currentPage;
            } while (count($filePage) === $maxNumItemsPerPage);

            foreach ($fileDataCollection as $fileData) {
                try {
                    $this->blobService->removeFile($fileData->getIdentifier());
                } catch (\Exception $exception) {
                    throw $this->createBlobApiError($exception);
                }
            }
        } catch (\Exception $exception) {
            throw $this->createBlobApiError($exception, 'Removing files failed');
        }
    }

    /**
     * @throws BlobApiError
     */
    public function getFile(string $identifier, array $options = []): BlobFile
    {
        return self::createBlobFileFromFileData($this->getFileData($identifier, $options));
    }

    /**
     * @throws BlobApiError
     */
    public function getFiles(int $currentPage = 1, int $maxNumItemsPerPage = 30, array $options = []): array
    {
        try {
            $filter = null;
            // ----------------------------
            // backwards compatibility:
            if (($prefixFilter = $options[BlobApi::PREFIX_OPTION] ?? null) !== null) {
                $filterTreeBuilder = FilterTreeBuilder::create();
                if ($options[BlobApi::PREFIX_STARTS_WITH_OPTION] ?? false) {
                    FilterTreeBuilder::create()->iStartsWith('prefix', $prefixFilter);
                } else {
                    FilterTreeBuilder::create()->equals('prefix', $prefixFilter);
                }
                $filter = $filterTreeBuilder->createFilter();
            }

            return $this->blobService->getFiles($this->bucketIdentifier, $filter, $options, $currentPage, $maxNumItemsPerPage);
        } catch (\Exception $exception) {
            throw $this->createBlobApiError($exception, 'Getting files failed');
        }
    }

    /**
     * @throws BlobApiError
     */
    public function getFileResponse(string $identifier, array $options = []): Response
    {
        try {
            return $this->blobService->getBinaryResponse($identifier, [
                BlobApi::DISABLE_OUTPUT_VALIDATION_OPTION => true,
                BlobService::UPDATE_LAST_ACCESS_TIMESTAMP_OPTION => true,
            ]);
        } catch (\Exception $exception) {
            throw $this->createBlobApiError($exception, 'Downloading file failed');
        }
    }

    /**
     * @throws BlobApiError
     */
    private function getFileData(string $identifier, array $options = []): FileData
    {
        if ($blobBaseUrl = $this->tryGetBlobBaseUrlFromCurrentRequest()) {
            $options[BlobService::BASE_URL_OPTION] = $blobBaseUrl;
        }

        try {
            return $this->blobService->getFileData($identifier, $options);
        } catch (\Exception $exception) {
            throw $this->createBlobApiError($exception, 'Getting file failed');
        }
    }

    private function createBlobApiError(\Exception $exception, ?string $message = null): BlobApiError
    {
        $errorId = BlobApiError::INTERNAL_ERROR;
        $statusCode = null;
        $blobErrorId = null;
        $blobErrorDetails = [];

        if ($exception instanceof ApiError) {
            $statusCode = $exception->getStatusCode();
            if ($exception->getStatusCode() === Response::HTTP_NOT_FOUND) {
                $errorId = BlobApiError::FILE_NOT_FOUND;
            } elseif ($statusCode >= 400 && $statusCode < 500) {
                $errorId = BlobApiError::CLIENT_ERROR;
            } elseif ($statusCode >= 500) {
                $errorId = BlobApiError::SERVER_ERROR;
            }
            $blobErrorId = $exception->getErrorId();
            $blobErrorDetails = $exception->getErrorDetails();
        }

        return new BlobApiError($message ?? $exception->getMessage(),
            $errorId, $statusCode, $blobErrorId, $blobErrorDetails, $exception);
    }

    private function tryGetBlobBaseUrlFromCurrentRequest(): ?string
    {
        return $this->requestStack->getCurrentRequest()?->getSchemeAndHttpHost();
    }
}
