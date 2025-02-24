<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\TestUtils;

use Dbp\Relay\BlobBundle\Service\DatasystemProviderServiceInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\Response;

class TestDatasystemProviderService implements DatasystemProviderServiceInterface
{
    private array $data;

    public function __construct()
    {
        $this->data = [];
    }

    public function hasFile(string $internalBucketId, string $fileId): bool
    {
        return isset($this->data[$internalBucketId][$fileId]);
    }

    public function saveFile(string $internalBucketId, string $fileId, File $file): void
    {
        $this->data[$internalBucketId][$fileId] = $file;
    }

    public function getBinaryResponse(string $internalBucketId, string $fileId): Response
    {
        if (!isset($this->data[$internalBucketId][$fileId])) {
            throw new \RuntimeException();
        }

        // build binary response
        $response = new BinaryFileResponse($this->data[$internalBucketId][$fileId]->getRealPath());

        return $response;
    }

    public function removeFile(string $internalBucketId, string $fileId): void
    {
        if (!isset($this->data[$internalBucketId][$fileId])) {
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
        if (!isset($this->data[$internalBucketId][$fileId])) {
            throw new \RuntimeException();
        }

        return $this->data[$internalBucketId][$fileId]->fileSize();
    }

    public function getFileHash(string $internalBucketId, string $fileId): string
    {
        if (!isset($this->data[$internalBucketId][$fileId])) {
            throw new \RuntimeException();
        }

        return hash('sha256', $this->data[$internalBucketId][$fileId]->getContent());
    }
}
