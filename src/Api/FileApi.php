<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Api;

use Dbp\Relay\BlobBundle\Entity\FileData;
use Dbp\Relay\BlobBundle\Service\BlobService;
use Dbp\Relay\BlobLibrary\Api\BlobApi;
use Dbp\Relay\BlobLibrary\Api\BlobApiError;
use Dbp\Relay\BlobLibrary\Api\BlobFile;
use Dbp\Relay\BlobLibrary\Api\BlobFileApiInterface;
use Dbp\Relay\BlobLibrary\Helpers\SignatureTools;
use Dbp\Relay\CoreBundle\Exception\ApiError;
use Dbp\Relay\CoreBundle\Rest\Query\Filter\FilterTreeBuilder;
use Psr\Http\Message\StreamInterface;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;

readonly class FileApi implements BlobFileApiInterface
{
    private static function generateTempFilePath(): string
    {
        $tempFilePath = tempnam(sys_get_temp_dir(), 'dbp_relay_blob_bundle_tempfile_');
        if ($tempFilePath === false) {
            throw new \RuntimeException('Could not create temporary file path');
        }

        return $tempFilePath;
    }

    /**
     * @return resource
     */
    private static function openTempFilePath(string $tempFilePath): mixed
    {
        $tempFileResource = fopen($tempFilePath, 'w');
        if ($tempFileResource === false) {
            throw new \RuntimeException('Could not open temporary file for writing');
        }

        return $tempFileResource;
    }

    /**
     * @throws BlobApiError
     */
    private static function createOrUpdateFileDataFromBlobFile(BlobFile $blobFile,
        ?string &$tempFilePath, ?FileData $fileData = null): FileData
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
                    $filePath = $file->getPathname();
                } elseif (is_string($file)) {
                    $tempFilePath = self::generateTempFilePath();
                    $filePath = $tempFilePath;
                    if (file_put_contents($tempFilePath, $file) === false) {
                        throw new \RuntimeException('Could not write file to temporary file path');
                    }
                } elseif ($file instanceof StreamInterface) {
                    $tempFilePath = self::generateTempFilePath();
                    $tempFileResource = self::openTempFilePath($tempFilePath);
                    $filePath = $tempFilePath;
                    while (false === $file->eof()) {
                        $chunk = $file->read(1024);
                        fwrite($tempFileResource, $chunk);
                    }
                    fclose($tempFileResource);
                } elseif (is_resource($file)) {
                    $tempFilePath = self::generateTempFilePath();
                    $tempFileResource = self::openTempFilePath($tempFilePath);
                    $filePath = $tempFilePath;
                    while (!feof($file)) {
                        $chunk = fread($file, 1024);
                        fwrite($tempFileResource, $chunk);
                    }
                    fclose($tempFileResource);
                } else {
                    throw new BlobApiError('unsupported file object', BlobApiError::REQUIRED_PARAMETER_MISSING);
                }
                $symfonyFile = new File($filePath);
            }
            $fileData->setFile($symfonyFile);
        }

        return $fileData;
    }

    private static function toDatetimeString(\DateTimeImmutable $dateTime): string
    {
        return $dateTime->format(\DateTimeInterface::ATOM);
    }

    private static function createBlobFileFromFileData(FileData $fileData): BlobFile
    {
        $blobFile = new BlobFile();
        if ($fileData->getIdentifier()) {
            $blobFile->setIdentifier($fileData->getIdentifier());
        }
        if ($fileData->getPrefix()) {
            $blobFile->setPrefix($fileData->getPrefix());
        }
        if ($fileData->getFile()) {
            $blobFile->setFile($fileData->getFile());
        }
        if ($fileData->getType()) {
            $blobFile->setType($fileData->getType());
        }
        if ($fileData->getFileSize()) {
            $blobFile->setFileSize($fileData->getFileSize());
        }
        if ($fileData->getFileName()) {
            $blobFile->setFileName($fileData->getFileName());
        }
        if ($fileData->getFileHash()) {
            $blobFile->setFileHash($fileData->getFileHash());
        }
        if ($fileData->getMetadata()) {
            $blobFile->setMetadata($fileData->getMetadata());
        }
        if ($fileData->getMetadataHash()) {
            $blobFile->setMetadataHash($fileData->getMetadataHash());
        }
        if ($fileData->getContentUrl()) {
            $blobFile->setContentUrl($fileData->getContentUrl());
        }
        if ($fileData->getMimeType()) {
            $blobFile->setMimeType($fileData->getMimeType());
        }
        if ($fileData->getDeleteAt()) {
            $blobFile->setDeleteAt(self::toDatetimeString($fileData->getDeleteAt()));
        }
        if ($fileData->getNotifyEmail()) {
            $blobFile->setNotifyEmail($fileData->getNotifyEmail());
        }
        if ($fileData->getDateCreated()) {
            $blobFile->setDateCreated(self::toDatetimeString($fileData->getDateCreated()));
        }
        if ($fileData->getDateModified()) {
            $blobFile->setDateModified(self::toDatetimeString($fileData->getDateModified()));
        }
        if ($fileData->getDateAccessed()) {
            $blobFile->setDateAccessed(self::toDatetimeString($fileData->getDateAccessed()));
        }

        return $blobFile;
    }

    public function __construct(
        private BlobService $blobService,
        private RequestStack $requestStack)
    {
    }

    /**
     * @throws BlobApiError
     */
    public function addFile(string $bucketIdentifier, BlobFile $blobFile, array $options = []): BlobFile
    {
        try {
            $tempFilePath = null;
            $fileData = self::createOrUpdateFileDataFromBlobFile($blobFile, $tempFilePath);
            $fileData->setBucketId($bucketIdentifier);

            if ($blobBaseUrl = $this->tryGetBlobBaseUrlFromCurrentRequest()) {
                $options[BlobService::BASE_URL_OPTION] = $blobBaseUrl;
            }

            try {
                return self::createBlobFileFromFileData($this->blobService->addFile($fileData, $options));
            } catch (\Exception $exception) {
                throw $this->createBlobApiError($exception, 'Adding file failed');
            }
        } finally {
            if ($tempFilePath !== null) {
                @unlink($tempFilePath);
            }
        }
    }

    /**
     * @throws BlobApiError
     */
    public function updateFile(string $bucketIdentifier, BlobFile $blobFile, array $options = []): BlobFile
    {
        try {
            $tempFilePath = null;

            $options[BlobService::UPDATE_LAST_ACCESS_TIMESTAMP_OPTION] = false;
            $options[BlobApi::DISABLE_OUTPUT_VALIDATION_OPTION] = true;
            $options[BlobService::ASSERT_BUCKET_ID_EQUALS_OPTION] = $bucketIdentifier;

            $fileData = $this->getFileData($bucketIdentifier, $blobFile->getIdentifier(), $options);
            $previousFileData = clone $fileData;

            $fileData = self::createOrUpdateFileDataFromBlobFile($blobFile, $tempFilePath, $fileData);

            return self::createBlobFileFromFileData(
                $this->blobService->updateFile($fileData, $previousFileData)
            );
        } catch (\Exception $exception) {
            throw $this->createBlobApiError($exception, 'Updating file failed');
        } finally {
            if ($tempFilePath !== null) {
                @unlink($tempFilePath);
            }
        }
    }

    /**
     * @throws BlobApiError
     */
    public function removeFile(string $bucketIdentifier, string $identifier, array $options = []): void
    {
        try {
            $options[BlobService::ASSERT_BUCKET_ID_EQUALS_OPTION] = $bucketIdentifier;
            $this->blobService->removeFile($identifier, options: $options);
        } catch (\Exception $exception) {
            throw $this->createBlobApiError($exception, 'Removing file failed');
        }
    }

    /**
     * @throws BlobApiError
     */
    public function removeFiles(string $bucketIdentifier, array $options = []): void
    {
        // TODO: batch remove?
        $currentPage = 1;
        $maxNumItemsPerPage = 100;
        $fileDataCollection = [];

        try {
            do {
                $filePage = $this->getFiles($bucketIdentifier, $currentPage, $maxNumItemsPerPage, $options);
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
    public function getFile(string $bucketIdentifier, string $identifier, array $options = []): BlobFile
    {
        return self::createBlobFileFromFileData($this->getFileData($bucketIdentifier, $identifier, $options));
    }

    /**
     * @return BlobFile[]
     *
     * @throws BlobApiError
     */
    public function getFiles(string $bucketIdentifier, int $currentPage = 1, int $maxNumItemsPerPage = 30, array $options = []): array
    {
        try {
            // ----------------------------
            // backwards compatibility:
            if (($prefixFilter = $options[BlobApi::PREFIX_OPTION] ?? null) !== null) {
                $filterTreeBuilder = FilterTreeBuilder::create();
                if ($options[BlobApi::PREFIX_STARTS_WITH_OPTION] ?? false) {
                    $filterTreeBuilder->iStartsWith('prefix', $prefixFilter);
                } else {
                    $filterTreeBuilder->equals('prefix', $prefixFilter);
                }
                $filter = $filterTreeBuilder->createFilter();
            } else {
                $filter = $options['filter'] ?? null;
            }

            if ($blobBaseUrl = $this->tryGetBlobBaseUrlFromCurrentRequest()) {
                $options[BlobService::BASE_URL_OPTION] = $blobBaseUrl;
            }

            $blobFiles = [];
            foreach ($this->blobService->getFiles($bucketIdentifier, $filter, $options, $currentPage, $maxNumItemsPerPage) as $fileData) {
                $blobFiles[] = self::createBlobFileFromFileData($fileData);
            }

            return $blobFiles;
        } catch (\Exception $exception) {
            throw $this->createBlobApiError($exception, 'Getting files failed');
        }
    }

    /**
     * @throws BlobApiError
     */
    public function getFileResponse(string $bucketIdentifier, string $identifier, array $options = []): Response
    {
        try {
            return $this->blobService->getBinaryResponse($identifier, [
                BlobApi::DISABLE_OUTPUT_VALIDATION_OPTION => true,
                BlobService::UPDATE_LAST_ACCESS_TIMESTAMP_OPTION => true,
                BlobService::ASSERT_BUCKET_ID_EQUALS_OPTION => $bucketIdentifier,
            ]);
        } catch (\Exception $exception) {
            throw $this->createBlobApiError($exception, 'Downloading file failed');
        }
    }

    /**
     * @throws BlobApiError
     */
    public function createSignedUrl(string $bucketIdentifier, string $method, array $parameters = [],
        array $options = [], ?string $identifier = null, ?string $action = null): string
    {
        $bucketConfig = $this->blobService->getConfigurationService()->getBucketById($bucketIdentifier);
        if ($bucketConfig === null) {
            throw new BlobApiError('bucket not configured', BlobApiError::CONFIGURATION_INVALID);
        }

        return SignatureTools::createSignedUrl($bucketIdentifier, $bucketConfig->getKey(),
            $method, $this->tryGetBlobBaseUrlFromCurrentRequest() ?? '', $identifier, $action, $parameters, $options);
    }

    /**
     * @throws BlobApiError
     */
    private function getFileData(string $bucketIdentifier, string $identifier, array $options = []): FileData
    {
        if ($blobBaseUrl = $this->tryGetBlobBaseUrlFromCurrentRequest()) {
            $options[BlobService::BASE_URL_OPTION] = $blobBaseUrl;
        }
        $options[BlobService::ASSERT_BUCKET_ID_EQUALS_OPTION] = $bucketIdentifier;

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
