<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Api;

use Dbp\Relay\BlobBundle\Entity\FileData;
use Dbp\Relay\BlobBundle\Helper\BlobUtils;
use Dbp\Relay\BlobBundle\Service\BlobService;
use Dbp\Relay\BlobLibrary\Api\BlobApi;
use Dbp\Relay\BlobLibrary\Api\BlobApiError;
use Dbp\Relay\BlobLibrary\Api\BlobFile;
use Dbp\Relay\BlobLibrary\Api\BlobFileApiInterface;
use Dbp\Relay\BlobLibrary\Api\BlobFileStream;
use Dbp\Relay\BlobLibrary\Helpers\SignatureTools;
use Dbp\Relay\CoreBundle\Exception\ApiError;
use Dbp\Relay\CoreBundle\Rest\Query\Filter\FilterTreeBuilder;
use Dbp\Relay\CoreBundle\Rest\Query\Pagination\Pagination;
use Psr\Http\Message\StreamInterface;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;

readonly class FileApi implements BlobFileApiInterface
{
    private static function generateTempFilePath(): string
    {
        $tempFilePath = tempnam(sys_get_temp_dir(), 'dbp_relay_blob_bundle_api_file_api_');
        if ($tempFilePath === false) {
            throw new \RuntimeException('Could not create a unique temporary file path,');
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
     * @throws \Exception
     */
    private static function createOrUpdateFileDataFromBlobFile(BlobFile $blobFile,
        ?string &$tempFilePath, ?FileData $fileData = null, array $options = []): FileData
    {
        $fileData ??= new FileData();
        if (($identifier = $blobFile->getIdentifier()) !== null) {
            $fileData->setIdentifier($identifier);
        }
        if (($prefix = $blobFile->getPrefix()) !== null) {
            $fileData->setPrefix($prefix);
        }
        if (($fileName = $blobFile->getFileName()) !== null) {
            $fileData->setFileName($fileName);
        }
        if (($type = $blobFile->getType()) !== null) {
            $fileData->setType($type);
        }
        if (($metadata = $blobFile->getMetadata()) !== null) {
            $fileData->setMetadata($metadata);
        }
        if (($file = $blobFile->getFile()) !== null) {
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
                    try {
                        while (false === $file->eof()) {
                            $chunk = $file->read(2048);
                            fwrite($tempFileResource, $chunk);
                        }
                    } finally {
                        fclose($tempFileResource);
                    }
                } else {
                    throw new BlobApiError('unsupported file object', BlobApiError::REQUIRED_PARAMETER_MISSING);
                }
                $symfonyFile = new File($filePath);
            }
            $fileData->setFile($symfonyFile);
        }
        if (($deleteIn = $options[BlobApi::DELETE_IN_OPTION] ?? null) !== null) {
            $fileData->setDeleteAt($deleteIn === 'null' ?
                null : BlobUtils::now()->add(new \DateInterval($deleteIn)));
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
            $fileData = self::createOrUpdateFileDataFromBlobFile($blobFile, $tempFilePath, options: $options);
            $fileData->setBucketId($bucketIdentifier);

            if (($blobBaseUrl = $this->tryGetBlobBaseUrlFromCurrentRequest()) !== null) {
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

            $fileData = $this->getFileData($bucketIdentifier, $blobFile->getIdentifier(),
                self::createGetFileDataOptions($options, true));

            $previousFileData = clone $fileData;
            $fileData = self::createOrUpdateFileDataFromBlobFile($blobFile, $tempFilePath, $fileData, options: $options);

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
        $this->removeFileInternal($bucketIdentifier, $identifier, $options);
    }

    /**
     * @throws BlobApiError
     */
    public function removeFiles(string $bucketIdentifier, array $options = []): void
    {
        foreach (Pagination::getAllResultsPageNumberBased(
            function (int $currentPageNumber, int $maxNumItemsPerPage) use ($bucketIdentifier, $options) {
                return $this->getFiles($bucketIdentifier, $currentPageNumber, $maxNumItemsPerPage, $options);
            }, 100) as $fileData) {
            $this->removeFileInternal($bucketIdentifier, $fileData->getIdentifier(), $options);
        }
    }

    /**
     * @throws BlobApiError
     */
    public function getFile(string $bucketIdentifier, string $identifier, array $options = []): BlobFile
    {
        try {
            return self::createBlobFileFromFileData(
                $this->getFileData($bucketIdentifier, $identifier,
                    self::createGetFileDataOptions($options, false)));
        } catch (\Exception $exception) {
            throw $this->createBlobApiError($exception, 'Getting file failed');
        }
    }

    /**
     * @return BlobFile[]
     *
     * @throws BlobApiError
     */
    public function getFiles(string $bucketIdentifier, int $currentPage = 1, int $maxNumItemsPerPage = 30, array $options = []): iterable
    {
        try {
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

            foreach ($this->blobService->getFiles($bucketIdentifier, $filter, $options, $currentPage, $maxNumItemsPerPage) as $fileData) {
                yield self::createBlobFileFromFileData($fileData);
            }
        } catch (\Exception $exception) {
            throw $this->createBlobApiError($exception, 'Getting files failed');
        }
    }

    /**
     * @throws BlobApiError
     */
    public function getFileStream(string $bucketIdentifier, string $identifier, array $options = []): BlobFileStream
    {
        try {
            $fileData = $this->getFileData($bucketIdentifier, $identifier,
                self::createGetFileDataOptions($options, true));

            return new BlobFileStream(
                $this->blobService->getFileStream($fileData),
                $fileData->getFileName(),
                $fileData->getMimeType(),
                $fileData->getFileSize()
            );
        } catch (\Exception $exception) {
            throw $this->createBlobApiError($exception, 'Getting file stream failed');
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
    private function removeFileInternal(string $bucketIdentifier, string $identifier, array $options = []): void
    {
        try {
            $fileData = $this->getFileData($bucketIdentifier, $identifier,
                self::createGetFileDataOptions($options, true));

            $this->blobService->removeFile($fileData, $options);
        } catch (\Exception $exception) {
            throw $this->createBlobApiError($exception, 'Removing file failed');
        }
    }

    /**
     * @throws \Exception
     */
    private function getFileData(string $bucketIdentifier, string $identifier, array $options = []): FileData
    {
        if (($blobBaseUrl = $this->tryGetBlobBaseUrlFromCurrentRequest()) !== null) {
            $options[BlobService::BASE_URL_OPTION] = $blobBaseUrl;
        }
        $options[BlobService::ASSERT_BUCKET_ID_EQUALS_OPTION] = $bucketIdentifier;

        return $this->blobService->getFileData($identifier, $options);
    }

    private function createBlobApiError(\Exception $exception, string $message): BlobApiError
    {
        $errorId = BlobApiError::INTERNAL_ERROR;
        $statusCode = null;
        $blobErrorId = null;
        $blobErrorDetails = [];

        if ($exception instanceof BlobApiError) {
            $message = $message.': '.$exception->getMessage();
            $errorId = $exception->getErrorId();
            $statusCode = $exception->getStatusCode();
            $blobErrorId = $exception->getBlobErrorId();
            $blobErrorDetails = $exception->getBlobErrorDetails();
        } elseif ($exception instanceof ApiError) {
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

        return new BlobApiError(
            $message, $errorId, $statusCode, $blobErrorId, $blobErrorDetails, $exception);
    }

    private function tryGetBlobBaseUrlFromCurrentRequest(): ?string
    {
        return $this->requestStack->getCurrentRequest()?->getSchemeAndHttpHost();
    }

    private static function createGetFileDataOptions(array &$options, bool $isInternalGet): array
    {
        $getFileDataOptions = [];

        if (($includeDeleteAt = $options[BlobApi::INCLUDE_DELETE_AT_OPTION] ?? null) !== null) {
            $getFileDataOptions[BlobApi::INCLUDE_DELETE_AT_OPTION] = $includeDeleteAt;
            unset($options[BlobApi::INCLUDE_DELETE_AT_OPTION]);
        }
        if (($disableOutputValidation = $options[BlobApi::DISABLE_OUTPUT_VALIDATION_OPTION] ?? null) !== null) {
            $getFileDataOptions[BlobApi::DISABLE_OUTPUT_VALIDATION_OPTION] = $disableOutputValidation;
            unset($options[BlobApi::DISABLE_OUTPUT_VALIDATION_OPTION]);
        }
        if (($includeFileContents = $options[BlobApi::INCLUDE_FILE_CONTENTS_OPTION] ?? null) !== null) {
            $getFileDataOptions[BlobApi::INCLUDE_FILE_CONTENTS_OPTION] = $includeFileContents;
            unset($options[BlobApi::INCLUDE_FILE_CONTENTS_OPTION]);
        }

        if ($isInternalGet) {
            $getFileDataOptions[BlobService::UPDATE_LAST_ACCESS_TIMESTAMP_OPTION] = false;
            $getFileDataOptions[BlobApi::DISABLE_OUTPUT_VALIDATION_OPTION] = true;
        }

        return $getFileDataOptions;
    }
}
