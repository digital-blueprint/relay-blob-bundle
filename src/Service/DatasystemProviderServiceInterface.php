<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Service;

use Dbp\Relay\BlobBundle\Entity\FileData;
use Dbp\Relay\BlobBundle\Helper\PoliciesStruct;

interface DatasystemProviderServiceInterface
{
    public function saveFile(FileData $fileData): ?FileData;

    public function renameFile(FileData $fileData): ?FileData;

    public function getLink(FileData $fileData, PoliciesStruct $policiesStruct): ?FileData;

    public function generateChecksum(FileData $fileData): ?string;

    public function removeFile(FileData $fileData): bool;
}
