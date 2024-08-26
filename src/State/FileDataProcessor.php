<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\State;

use Dbp\Relay\BlobBundle\Entity\FileData;
use Dbp\Relay\BlobBundle\Service\BlobService;
use Dbp\Relay\CoreBundle\Exception\ApiError;
use Dbp\Relay\CoreBundle\Rest\AbstractDataProcessor;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

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
        if (!Uuid::isValid($identifier)) {
            throw ApiError::withDetails(Response::HTTP_NOT_FOUND, 'Identifier is in an invalid format!', 'blob:identifier-invalid-format');
        }

        // no need to check, because signature is checked by getting the data
        assert($data instanceof FileData);

        $docBucket = $this->blobService->getBucketByInternalIdFromDatabase($data->getInternalBucketID());
        $this->blobService->writeToTablesAndRemoveFileData($data, $docBucket->getCurrentBucketSize() - $data->getFileSize());
    }
}
