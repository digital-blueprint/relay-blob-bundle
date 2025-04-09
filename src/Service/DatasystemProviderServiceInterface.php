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

    /**
     * Opens metadata backup to be able to write to it.
     *
     * @return bool true if successful, else false
     */
    public function openMetadataBackup(string $internalBucketId): bool;

    /**
     * Appends given string to backup.
     *
     * @return bool true if successful, else false
     */
    public function appendToMetadataBackup(string $item): bool;

    /**
     * Closes and saves metadata backup.
     *
     * @return bool true if successful, else false
     */
    public function closeMetadataBackup(string $internalBucketId): bool;

    /**
     * Calculates and gets the filehash of the metadata backup file.
     *
     * @return string sha256 filehash if successful, else null
     */
    public function getMetadataBackupFileHash(): ?string;

    /**
     * Gets the place where the metadata backup file is stored.
     *
     * @return string file ref if successful, else null
     */
    public function getMetadataBackupFileRef(): ?string;
}
