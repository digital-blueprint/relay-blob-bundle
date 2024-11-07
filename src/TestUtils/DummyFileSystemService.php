<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\TestUtils;

use Dbp\Relay\BlobBundle\Service\DatasystemProviderServiceInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\Response;

class DummyFileSystemService implements DatasystemProviderServiceInterface
{
    public static $data = [];

    public function saveFile(string $bucketId, string $fileId, File $file): void
    {
        self::$data[$bucketId][$fileId] = $file;
    }

    public function getBinaryResponse(string $bucketId, string $fileId): Response
    {
        if (!isset(self::$data[$bucketId][$fileId])) {
            throw new \RuntimeException();
        }

        // build binary response
        $response = new BinaryFileResponse(self::$data[$bucketId][$fileId]->getRealPath());

        return $response;
    }

    public function removeFile(string $bucketId, string $fileId): void
    {
        unset(self::$data[$bucketId][$fileId]);
    }

    public function getSumOfFilesizesOfBucket(string $bucketId): int
    {
        $sumOfFileSizes = 0;
        $files = self::$data[$bucketId] ?? [];
        foreach ($files as $file) {
            $sumOfFileSizes += $file->getFileSize();
        }

        return $sumOfFileSizes;
    }

    public function getNumberOfFilesInBucket(string $bucketId): int
    {
        $files = self::$data[$bucketId] ?? [];

        return count($files);
    }
}
