<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Service;

use Dbp\Relay\BlobBundle\Entity\FileData;
use Dbp\Relay\BlobBundle\Helper\PoliciesStruct;
use Symfony\Component\HttpFoundation\Response;

interface DatasystemProviderServiceInterface
{
    public function saveFile(FileData $fileData): ?FileData;

    public function renameFile(FileData $fileData): ?FileData;

    public function getLink(FileData $fileData, PoliciesStruct $policiesStruct): ?FileData;

    public function getBinaryResponse(FileData $fileData, PoliciesStruct $policiesStruct): Response;

    public function getBase64Data(FileData $fileData, PoliciesStruct $policiesStruct): FileData;

    public function generateChecksumFromFileData(FileData $fileData, string $validUntil = ''): ?string;

    public function removeFile(FileData $fileData): bool;
}
