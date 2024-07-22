<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\State;

use Dbp\Relay\BlobBundle\Entity\FileData;
use Dbp\Relay\BlobBundle\Service\BlobService;
use Dbp\Relay\CoreBundle\Rest\AbstractDataProcessor;

class FileDataProcessor extends AbstractDataProcessor
{
    private BlobService $blobService;

    public function __construct(BlobService $blobService)
    {
        parent::__construct();

        $this->blobService = $blobService;
    }

    /**
     * @throws \Exception
     */
    protected function removeItem(mixed $identifier, mixed $data, array $filters): void
    {
        // no need to check, because signature is checked by getting the data
        assert($data instanceof FileData);

        $docBucket = $this->blobService->getBucketByInternalIdFromDatabase($data->getInternalBucketID());
        $this->blobService->writeToTablesAndRemoveFileData($data, $docBucket->getCurrentBucketSize() - $data->getFileSize());
    }
}
