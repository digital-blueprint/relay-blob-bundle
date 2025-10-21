<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\ApiPlatform;

use Dbp\Relay\BlobBundle\Authorization\AuthorizationService;
use Dbp\Relay\BlobBundle\Entity\MetadataBackupJob;
use Dbp\Relay\BlobBundle\Entity\MetadataRestoreJob;
use Dbp\Relay\BlobBundle\Service\BlobService;
use Dbp\Relay\CoreBundle\Exception\ApiError;
use Dbp\Relay\CoreBundle\Rest\AbstractDataProcessor;
use Symfony\Component\HttpFoundation\Response;

/**
 * @internal
 */
class MetadataRestoreJobProcessor extends AbstractDataProcessor
{
    public function __construct(
        private readonly BlobService $blobService, private readonly AuthorizationService $authService)
    {
        parent::__construct();
    }

    protected function requiresAuthentication(int $operation): bool
    {
        return true;
    }

    /**
     * @throws \Exception
     */
    protected function addItem(mixed $data, array $filters): MetadataRestoreJob
    {
        assert($data instanceof MetadataRestoreJob);
        $job = $data;

        if (!array_key_exists('bucketIdentifier', $filters)) {
            throw ApiError::withDetails(
                Response::HTTP_BAD_REQUEST,
                'Bucket could not be found!',
                'blob:bucket-not-found'
            );
        }
        $bucketIdentifier = $filters['bucketIdentifier'];
        if ($bucketIdentifier === null) {
            throw ApiError::withDetails(
                Response::HTTP_BAD_REQUEST,
                'Bucket could not be found!',
                'blob:bucket-not-found'
            );
        }

        $internalId = $this->blobService->getInternalBucketIdByBucketId($bucketIdentifier);

        if ($internalId === null) {
            throw ApiError::withDetails(
                Response::HTTP_BAD_REQUEST,
                'Bucket could not be found!',
                'blob:bucket-not-found'
            );
        }

        $this->authService->checkCanAccessMetadataBackup();

        $this->blobService->setupMetadataRestoreJob($job, $internalId);
        try {
            $this->blobService->startMetadataRestore($job);
        } catch (\Exception $e) {
            $job->setStatus(MetadataRestoreJob::JOB_STATUS_ERROR);
            $job->setErrorMessage($e->getMessage());
            if ($e instanceof ApiError) {
                $job->setErrorId($e->getErrorId());
                $this->blobService->finishAndSaveMetadataRestoreJob($job, $internalId);
                throw ApiError::withDetails($e->getStatusCode(), $job->getErrorMessage(), $job->getErrorId());
            } else {
                $this->blobService->finishAndSaveMetadataRestoreJob($job, $internalId);
                throw ApiError::withDetails(Response::HTTP_INTERNAL_SERVER_ERROR, 'Something went wrong!');
            }
        }

        if ($this->blobService->getMetadataRestoreJobById($job->getIdentifier())->getStatus() === MetadataRestoreJob::JOB_STATUS_CANCELLED) {
            return $this->blobService->getMetadataRestoreJobById($job->getIdentifier());
        }
        $this->blobService->finishAndSaveMetadataRestoreJob($job, $internalId);

        return $job;
    }

    /**
     * @throws \Exception
     */
    protected function removeItem(mixed $identifier, mixed $job, array $filters): void
    {
        assert($job instanceof MetadataBackupJob);
        $backupJob = $job;

        $this->blobService->removeMetadataBackupJob($backupJob);
    }
}
