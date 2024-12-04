<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\ApiPlatform;

use Dbp\Relay\BlobBundle\Entity\BucketLock;
use Dbp\Relay\BlobBundle\Service\BlobService;
use Dbp\Relay\CoreBundle\Exception\ApiError;
use Dbp\Relay\CoreBundle\Rest\AbstractDataProcessor;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

class BucketLockProcessor extends AbstractDataProcessor
{
    public function __construct(
        private readonly BlobService $blobService)
    {
        parent::__construct();
    }

    protected function requiresAuthentication(int $operation): bool
    {
        return true;
    }

    protected function addItem(mixed $data, array $filters): BucketLock
    {
        assert($data instanceof BucketLock);
        $lock = $data;

        if (!array_key_exists('bucketIdentifier', $filters)) {
            throw ApiError::withDetails(
                Response::HTTP_BAD_REQUEST,
                'Bucket could not be found!',
                'blob:bucket-not-found'
            );
        }
        $internalId = $this->blobService->getInternalBucketIdByBucketID($filters['bucketIdentifier']);
        if ($internalId === null) {
            throw ApiError::withDetails(
                Response::HTTP_BAD_REQUEST,
                'Bucket could not be found!',
                'blob:bucket-not-found'
            );
        }

        if ($this->blobService->getBucketLockByInternalBucketId($internalId) !== null) {
            throw ApiError::withDetails(
                Response::HTTP_BAD_REQUEST,
                'Lock on bucket already exists!',
                'blob:lock-already-exists'
            );
        }

        $lock->setInternalBucketId($internalId);
        $lock->setIdentifier(Uuid::v7()->toRfc4122());
        $this->blobService->addBucketLock($lock);

        return $lock;
    }

    protected function updateItem(mixed $identifier, mixed $data, mixed $previousData, array $filters): BucketLock
    {
        assert($data instanceof BucketLock);
        assert($previousData instanceof BucketLock);
        $this->blobService->updateBucketLock($identifier, $filters, $data);

        return $data;
    }

    protected function removeItem(mixed $identifier, mixed $data, array $filters): void
    {
        assert($data instanceof BucketLock);

        $this->blobService->removeBucketLock($identifier);
    }
}
