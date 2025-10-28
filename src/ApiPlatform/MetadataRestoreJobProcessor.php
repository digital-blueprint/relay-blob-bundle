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

        if (!array_key_exists('metadataBackupJobId', $filters)) {
            throw ApiError::withDetails(
                Response::HTTP_BAD_REQUEST,
                'MetadataBackupJob could not be found!',
                'blob:metadata-backup-job-not-found'
            );
        }
        $metadataBackupJobId = $filters['metadataBackupJobId'];
        if ($metadataBackupJobId === null) {
            throw ApiError::withDetails(
                Response::HTTP_BAD_REQUEST,
                'MetadataBackupJob could not be found!',
                'blob:metadata-backup-job-not-found'
            );
        }

        $backupJob = $this->blobService->getMetadataBackupJobById($metadataBackupJobId);

        if ($backupJob === null) {
            throw ApiError::withDetails(
                Response::HTTP_BAD_REQUEST,
                'MetadataBackupJob could not be found!',
                'blob:metadata-backup-job-not-found'
            );
        }

        $this->authService->checkCanAccessMetadataBackup();

        $this->blobService->setupMetadataRestoreJob($job, $backupJob->getBucketId(), $metadataBackupJobId);
        try {
            $this->blobService->deleteBucketByInternalBucketId($backupJob->getBucketId());
            $this->blobService->startMetadataRestore($job);
        } catch (\Exception $e) {
            $job->setStatus(MetadataRestoreJob::JOB_STATUS_ERROR);
            $job->setErrorMessage($e->getMessage());
            if ($e instanceof ApiError) {
                $job->setErrorId($e->getErrorId());
                $this->blobService->finishAndSaveMetadataRestoreJob($job, $backupJob->getBucketId());
                throw ApiError::withDetails($e->getStatusCode(), $job->getErrorMessage(), $job->getErrorId());
            } else {
                $this->blobService->finishAndSaveMetadataRestoreJob($job, $backupJob->getBucketId());
                throw ApiError::withDetails(Response::HTTP_INTERNAL_SERVER_ERROR, 'Something went wrong!');
            }
        }

        if ($this->blobService->getMetadataRestoreJobById($job->getIdentifier())->getStatus() === MetadataRestoreJob::JOB_STATUS_CANCELLED) {
            return $this->blobService->getMetadataRestoreJobById($job->getIdentifier());
        }
        $this->blobService->finishAndSaveMetadataRestoreJob($job, $backupJob->getBucketId());

        return $job;
    }

    /**
     * @throws \Exception
     */
    protected function removeItem(mixed $identifier, mixed $job, array $filters): void
    {
        assert($job instanceof MetadataRestoreJob);
        $backupJob = $job;

        $this->blobService->removeMetadataBackupJob($backupJob);
    }
}
