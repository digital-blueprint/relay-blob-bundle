<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Tests;

use Dbp\Relay\BlobBundle\Entity\FileData;
use Dbp\Relay\BlobBundle\Service\DatasystemProviderServiceInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

class DummyFileSystemService implements DatasystemProviderServiceInterface
{
    public static $fd = [];
    public static $data = [];

    public function saveFile(FileData $fileData): void
    {
        self::$fd[$fileData->getIdentifier()] = $fileData;
        self::$data[$fileData->getIdentifier()] = $fileData->getFile();
    }

    public function getContentUrl(FileData $fileData): string
    {
        $identifier = $fileData->getIdentifier();
        if (!isset(self::$fd[$identifier])) {
            echo "    DummyFileSystemService::getLink($identifier): not found!\n";
        }

        // build binary response
        $file = file_get_contents(self::$data[$identifier]->getRealPath());
        $mimeType = self::$data[$identifier]->getMimeType();

        return 'data:'.$mimeType.';base64,'.base64_encode($file);
    }

    public function getBinaryResponse(FileData $fileData): Response
    {
        $identifier = $fileData->getIdentifier();
        if (!isset(self::$fd[$identifier])) {
            echo "    DummyFileSystemService::getLink($identifier): not found!\n";
        }

        // build binary response
        $response = new BinaryFileResponse(self::$data[$identifier]->getRealPath());
        $response->headers->set('Content-Type', self::$data[$identifier]->getMimeType());
        $filename = $fileData->getFileName();

        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $filename
        );

        return $response;
    }

    public function removeFile(FileData $fileData): void
    {
        unset(self::$fd[$fileData->getIdentifier()]);
        unset(self::$data[$fileData->getIdentifier()]);
    }

    public function getSumOfFilesizesOfBucket(string $bucketId): int
    {
        $sumOfFileSizes = 0;

        /** @var FileData $data */
        foreach (self::$fd as $data) {
            if ($data->getInternalBucketID() === $bucketId) {
                $sumOfFileSizes += $data->getFileSize();
            }
        }

        return $sumOfFileSizes;
    }

    public function getNumberOfFilesInBucket(string $bucketId): int
    {
        $numOfFiles = 0;

        /** @var FileData $data */
        foreach (self::$fd as $data) {
            if ($data->getInternalBucketID() === $bucketId) {
                ++$numOfFiles;
            }
        }

        return $numOfFiles;
    }
}
