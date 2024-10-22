<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Service;

use Dbp\Relay\BlobBundle\Entity\FileData;
use Symfony\Component\HttpFoundation\Response;

interface DatasystemProviderServiceInterface
{
    public function saveFile(FileData $fileData): ?FileData;

    public function getBinaryResponse(FileData $fileData): Response;

    public function getBase64Data(FileData $fileData): FileData;

    public function removeFile(FileData $fileData): bool;

    public function getSumOfFilesizesOfBucket(string $bucketId): int;

    public function getNumberOfFilesInBucket(string $bucketId): int;
}
