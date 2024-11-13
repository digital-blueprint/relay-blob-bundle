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

    public function getSumOfFilesizesOfBucket(string $internalBucketId): int;

    public function getNumberOfFilesInBucket(string $internalBucketId): int;
}
