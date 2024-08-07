<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Service;

use Dbp\Relay\BlobBundle\Entity\Bucket;
use Dbp\Relay\BlobBundle\Entity\FileData;
use Symfony\Component\HttpFoundation\Response;

interface DatasystemProviderServiceInterface
{
    public function saveFile(FileData $fileData): ?FileData;

    public function saveFileFromString(FileData $fileData, string $data): ?FileData;

    public function renameFile(FileData $fileData): ?FileData;

    public function getLink(FileData $fileData): ?FileData;

    public function getBinaryResponse(FileData $fileData): Response;

    public function getBase64Data(FileData $fileData): FileData;

    public function generateChecksumFromFileData(FileData $fileData, string $validUntil = ''): ?string;

    public function removeFile(FileData $fileData): bool;

    public function getSumOfFilesizesOfBucket(Bucket $bucket): int;

    public function getNumberOfFilesInBucket(Bucket $bucket): int;
}
