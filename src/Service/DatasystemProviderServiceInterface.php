<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Service;

use Dbp\Relay\BlobBundle\Entity\FileData;
use Symfony\Component\HttpFoundation\Response;

interface DatasystemProviderServiceInterface
{
    public function saveFile(FileData $fileData): void;

    public function getBinaryResponse(FileData $fileData): Response;

    public function getContentUrl(FileData $fileData): string;

    public function removeFile(FileData $fileData): void;

    public function getSumOfFilesizesOfBucket(string $bucketId): int;

    public function getNumberOfFilesInBucket(string $bucketId): int;
}
