<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Service;

use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\Response;

interface DatasystemProviderServiceInterface
{
    public function saveFile(string $internalBucketId, string $fileId, File $file): void;

    public function getBinaryResponse(string $internalBucketId, string $fileId): Response;

    public function removeFile(string $internalBucketId, string $fileId): void;

    public function getFileSize(string $internalBucketId, string $fileId): int;

    /**
     * Returns SHA256 hash of the file content.
     */
    public function getFileHash(string $internalBucketId, string $fileId): string;

    /**
     * Returns true if the file exists, i.e. if getBinaryResponse() would return something.
     */
    public function hasFile(string $internalBucketId, string $fileId): bool;

    /**
     * Returns an iterable over all available file IDs. i.e. getFileExists() should
     * return true for all of them.
     *
     * @return iterable<string>
     */
    public function listFiles(string $internalBucketId): iterable;
}
