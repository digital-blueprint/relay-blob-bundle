<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Service;

use Psr\Http\Message\StreamInterface;

interface DatasystemProviderServiceInterface
{
    public function saveFile(string $internalBucketId, string $fileId, \SplFileInfo $file): void;

    public function getFileStream(string $internalBucketId, string $fileId): StreamInterface;

    public function removeFile(string $internalBucketId, string $fileId): void;

    public function getFileSize(string $internalBucketId, string $fileId): int;

    /**
     * Returns SHA256 hash of the file content.
     */
    public function getFileHash(string $internalBucketId, string $fileId): string;

    /**
     * Returns true if the file exists, i.e., if getFileStream() would return something.
     */
    public function hasFile(string $internalBucketId, string $fileId): bool;

    /**
     * Returns an iterator over all available file IDs, i.e., getFileExists() should
     * return true for all of them.
     *
     * @return iterable<string>
     */
    public function listFiles(string $internalBucketId): iterable;
}
