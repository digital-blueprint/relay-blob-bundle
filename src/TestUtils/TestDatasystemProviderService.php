<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\TestUtils;

use Dbp\Relay\BlobBundle\Service\DatasystemProviderServiceInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\Response;

class TestDatasystemProviderService implements DatasystemProviderServiceInterface
{
    public static $data = [];

    public function hasFile(string $internalBucketId, string $fileId): bool
    {
        return isset(self::$data[$internalBucketId][$fileId]);
    }

    public static function isContentEqual(string $internalBucketId, string $fileId, File $fileToCompare): bool
    {
        $file = self::$data[$internalBucketId][$fileId] ?? null;

        return $file !== null && $file->getContent() === $fileToCompare->getContent();
    }

    public function saveFile(string $internalBucketId, string $fileId, File $file): void
    {
        self::$data[$internalBucketId][$fileId] = $file;
    }

    public function getBinaryResponse(string $internalBucketId, string $fileId): Response
    {
        if (!isset(self::$data[$internalBucketId][$fileId])) {
            throw new \RuntimeException();
        }

        // build binary response
        $response = new BinaryFileResponse(self::$data[$internalBucketId][$fileId]->getRealPath());

        return $response;
    }

    public function removeFile(string $internalBucketId, string $fileId): void
    {
        unset(self::$data[$internalBucketId][$fileId]);
    }

    public function getSumOfFilesizesOfBucket(string $internalBucketId): int
    {
        $sumOfFileSizes = 0;
        $files = self::$data[$internalBucketId] ?? [];
        foreach ($files as $file) {
            $sumOfFileSizes += $file->getFileSize();
        }

        return $sumOfFileSizes;
    }

    public function getNumberOfFilesInBucket(string $internalBucketId): int
    {
        $files = self::$data[$internalBucketId] ?? [];

        return count($files);
    }

    public function listFiles(string $internalBucketId): iterable
    {
        return array_keys(self::$data[$internalBucketId] ?? []);
    }
}
