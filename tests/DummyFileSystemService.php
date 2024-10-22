<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Tests;

use Dbp\Relay\BlobBundle\Entity\Bucket;
use Dbp\Relay\BlobBundle\Entity\FileData;
use Dbp\Relay\BlobBundle\Service\DatasystemProviderServiceInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

class DummyFileSystemService implements DatasystemProviderServiceInterface
{
    public static $fd = [];
    public static $data = [];

    public function saveFile(FileData $fileData): ?FileData
    {
        self::$fd[$fileData->getIdentifier()] = $fileData;
        self::$data[$fileData->getIdentifier()] = $fileData->getFile();

        return $fileData;
    }

    public function getBase64Data(FileData $fileData): FileData
    {
        $identifier = $fileData->getIdentifier();
        if (!isset(self::$fd[$identifier])) {
            echo "    DummyFileSystemService::getLink($identifier): not found!\n";
        }

        // build binary response
        $file = file_get_contents(self::$data[$identifier]->getRealPath());
        $mimeType = self::$data[$identifier]->getMimeType();

        $filename = $fileData->getFileName();

        $fileData->setContentUrl('data:'.$mimeType.';base64,'.base64_encode($file));
        self::$fd[$identifier] = $fileData;

        return self::$fd[$identifier];
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

    public function removeFile(FileData $fileData): bool
    {
        unset(self::$fd[$fileData->getIdentifier()]);
        unset(self::$data[$fileData->getIdentifier()]);

        return true;
    }

    public function getSumOfFilesizesOfBucket(Bucket $bucket): int
    {
        $sumOfFileSizes = 0;

        /** @var FileData $data */
        foreach (self::$fd as $data) {
            if ($data->getInternalBucketID() === $bucket->getIdentifier()) {
                $sumOfFileSizes += $data->getFileSize();
            }
        }

        return $sumOfFileSizes;
    }

    public function getNumberOfFilesInBucket(Bucket $bucket): int
    {
        $numOfFiles = 0;

        /** @var FileData $data */
        foreach (self::$fd as $data) {
            if ($data->getInternalBucketID() === $bucket->getIdentifier()) {
                ++$numOfFiles;
            }
        }

        return $numOfFiles;
    }
}
