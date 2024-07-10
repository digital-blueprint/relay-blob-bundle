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

    public function renameFile(FileData $fileData): ?FileData
    {
        self::$fd[$fileData->getIdentifier()] = $fileData;

        return $fileData;
    }

    public function getLink(FileData $fileData): ?FileData
    {
        $identifier = $fileData->getIdentifier();
        if (!isset(self::$fd[$identifier])) {
            echo "    DummyFileSystemService::getLink($identifier): not found!\n";

            return null;
        }

        $fileData->setContentUrl("https://localhost.lan/link/$identifier");
        self::$fd[$identifier] = $fileData;

        return self::$fd[$identifier];
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

    public function generateChecksumFromFileData($fileData, $validUntil = ''): ?string
    {
        // if no validUntil is given, use bucket link expiry time per default
        if ($validUntil === '') {
            $now = new \DateTimeImmutable('now', new DateTimeZone('UTC'));
            $now = $now->add(new \DateInterval($fileData->getBucket()->getLinkExpireTime()));
            $validUntil = $now->format('c');
        }

        // create url to hash
        $contentUrl = '/blob/filesystem/'.$fileData->getIdentifier().'?validUntil='.$validUntil;

        // create hmac sha256 keyed hash
        // $cs = hash_hmac('sha256', $contentUrl, $fileData->getBucket()->getKey());

        // create sha256 hash
        $cs = hash('sha256', $contentUrl);

        return $cs;
    }

    public function saveFileFromString(FileData $fileData, string $data): ?FileData
    {
        self::$fd[$fileData->getIdentifier()] = $fileData;
        self::$data[$fileData->getIdentifier()] = $fileData->getFile();

        return $fileData;
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
