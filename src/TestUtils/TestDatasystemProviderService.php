<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\TestUtils;

use Dbp\Relay\BlobBundle\Service\DatasystemProviderServiceInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\Response;

class TestDatasystemProviderService implements DatasystemProviderServiceInterface
{
    private static ?array $data = null;

    public function __construct()
    {
        if (self::$data !== null) {
            self::cleanup();
        }
        self::$data = [];
    }

    public function hasFile(string $internalBucketId, string $fileId): bool
    {
        return isset(self::$data[$internalBucketId][$fileId]);
    }

    public function saveFile(string $internalBucketId, string $fileId, File $file): void
    {
        if (isset(self::$data[$internalBucketId][$fileId])) {
            @unlink(self::$data[$internalBucketId][$fileId]->getRealPath());
        }

        $tempFilePath = tempnam(sys_get_temp_dir(), 'dbp_relay_blob_bundle_unit_test_tempfile_');
        copy($file->getRealPath(), $tempFilePath);

        self::$data[$internalBucketId][$fileId] = new File($tempFilePath);
    }

    public function getBinaryResponse(string $internalBucketId, string $fileId): Response
    {
        if (!isset(self::$data[$internalBucketId][$fileId])) {
            throw new \RuntimeException();
        }

        return new BinaryFileResponse(self::$data[$internalBucketId][$fileId]->getRealPath());
    }

    public function removeFile(string $internalBucketId, string $fileId): void
    {
        if (!isset(self::$data[$internalBucketId][$fileId])) {
            throw new \RuntimeException();
        }

        @unlink(self::$data[$internalBucketId][$fileId]->getRealPath());
        unset(self::$data[$internalBucketId][$fileId]);
    }

    public function listFiles(string $internalBucketId): iterable
    {
        return array_keys(self::$data[$internalBucketId] ?? []);
    }

    public function getFileSize(string $internalBucketId, string $fileId): int
    {
        if (!isset(self::$data[$internalBucketId][$fileId])) {
            throw new \RuntimeException();
        }

        return self::$data[$internalBucketId][$fileId]->fileSize();
    }

    public function getFileHash(string $internalBucketId, string $fileId): string
    {
        if (!isset(self::$data[$internalBucketId][$fileId])) {
            throw new \RuntimeException();
        }

        return hash('sha256', self::$data[$internalBucketId][$fileId]->getContent());
    }

    public static function cleanup(): void
    {
        if (self::$data !== null) {
            foreach (self::$data as $files) {
                foreach ($files as $file) {
                    @unlink($file->getRealPath());
                }
            }
        }
        self::$data = null;
    }
}
