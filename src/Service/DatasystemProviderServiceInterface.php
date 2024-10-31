<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Service;

use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\Response;

interface DatasystemProviderServiceInterface
{
    public function saveFile(string $bucketId, string $fileId, File $file): void;

    public function getBinaryResponse(string $bucketId, string $fileId): Response;

    public function removeFile(string $bucketId, string $fileId): void;

    public function getSumOfFilesizesOfBucket(string $bucketId): int;

    public function getNumberOfFilesInBucket(string $bucketId): int;
}
