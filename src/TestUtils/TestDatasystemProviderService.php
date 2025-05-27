<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\TestUtils;

use Dbp\Relay\BlobBundle\Service\DatasystemProviderServiceInterface;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\StreamInterface;

class TestDatasystemProviderService implements DatasystemProviderServiceInterface
{
    /** @var array<string, array<string, string>> */
    private array $data = [];

    public function __construct()
    {
    }

    public function hasFile(string $internalBucketId, string $fileId): bool
    {
        return isset($this->data[$internalBucketId][$fileId]);
    }

    public function saveFile(string $internalBucketId, string $fileId, \SplFileInfo $file): void
    {
        $this->data[$internalBucketId][$fileId] = file_get_contents($file->getRealPath());
    }

    public function removeFile(string $internalBucketId, string $fileId): void
    {
        if (false === isset($this->data[$internalBucketId][$fileId])) {
            throw new \RuntimeException();
        }

        unset($this->data[$internalBucketId][$fileId]);
    }

    public function listFiles(string $internalBucketId): iterable
    {
        return array_keys($this->data[$internalBucketId] ?? []);
    }

    public function getFileSize(string $internalBucketId, string $fileId): int
    {
        if (false === isset($this->data[$internalBucketId][$fileId])) {
            throw new \RuntimeException();
        }

        return strlen($this->data[$internalBucketId][$fileId]);
    }

    public function getFileHash(string $internalBucketId, string $fileId): string
    {
        if (false === isset($this->data[$internalBucketId][$fileId])) {
            throw new \RuntimeException();
        }

        return hash('sha256', $this->data[$internalBucketId][$fileId]);
    }

    public function getFileStream(string $internalBucketId, string $fileId): StreamInterface
    {
        if (false === isset($this->data[$internalBucketId][$fileId])) {
            throw new \RuntimeException();
        }

        return Utils::streamFor($this->data[$internalBucketId][$fileId]);
    }

    /**
     * @deprecated
     */
    public static function cleanup(): void
    {
    }
}
