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

    private mixed $backupFile;

    private ?string $backupFilePath = null;

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

    public function openMetadataBackup(string $internalBucketId, string $mode): bool
    {
        if ($this->backupFilePath === null) {
            $this->backupFilePath = tempnam(sys_get_temp_dir(), 'blob_backup_');
        }

        $ret = fopen($this->backupFilePath, $mode);

        if ($ret !== false) {
            $this->backupFile = $ret;
        }

        return $ret !== false;
    }

    public function appendToMetadataBackup(string $item): bool
    {
        $ret = fwrite($this->backupFile, $item);

        return $ret !== false;
    }

    public function retrieveItemFromMetadataBackup(): string|false
    {
        $ret = fgets($this->backupFile);

        if (!$ret && !feof($this->backupFile)) {
            throw new \RuntimeException('Could not read line from metadata backup!');
        }

        return $ret;
    }

    public function closeMetadataBackup(string $internalBucketId, bool $restoreOldBackup = false): bool
    {
        $ret = fclose($this->backupFile);

        if ($this->backupFilePath !== null && file_exists($this->backupFilePath)) {
            unlink($this->backupFilePath);
            $this->backupFilePath = null;
        }

        return $ret !== false;
    }

    public function getMetadataBackupFileHash(string $intBucketId): ?string
    {
        if ($this->backupFilePath === null) {
            return null;
        }

        $ret = hash_file('sha256', $this->backupFilePath);

        if ($ret === false) {
            return null;
        }

        return $ret;
    }

    public function getMetadataBackupFileRef(string $intBucketId): ?string
    {
        return $this->backupFilePath;
    }

    public function hasNextItemInMetadataBackup(): bool
    {
        return feof($this->backupFile);
    }
}
