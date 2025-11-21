<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\ApiPlatform;

use Dbp\Relay\BlobBundle\Authorization\AuthorizationService;
use Dbp\Relay\BlobBundle\Entity\MetadataBackupJob;
use Dbp\Relay\BlobBundle\Entity\MetadataRestoreJob;
use Dbp\Relay\BlobBundle\Service\BlobService;
use Dbp\Relay\BlobBundle\Task\MetadataRestoreTask;
use Dbp\Relay\CoreBundle\Exception\ApiError;
use Dbp\Relay\CoreBundle\Rest\AbstractDataProcessor;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * @internal
 */
class MetadataRestoreJobProcessor extends AbstractDataProcessor
{
    public function __construct(
        private readonly BlobService $blobService, private readonly AuthorizationService $authService, private MessageBusInterface $messageBus)
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

        $this->authService->checkCanAccessMetadataBackup();

        if (!array_key_exists('metadataBackupJobId', $filters)) {
            throw ApiError::withDetails(
                Response::HTTP_BAD_REQUEST,
                'MetadataBackupJob could not be found!',
                'blob:metadata-backup-job-not-found'
            );
        }
        $metadataBackupJobId = $filters['metadataBackupJobId'];
        if (empty($metadataBackupJobId)) {
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

        if ($backupJob->getStatus() !== MetadataBackupJob::JOB_STATUS_FINISHED) {
            throw ApiError::withDetails(
                Response::HTTP_BAD_REQUEST,
                'Requested MetadataBackupJob is not finished!',
                'blob:metadata-backup-job-not-finished'
            );
        }

        $this->blobService->setupMetadataRestoreJob($job, $backupJob->getBucketId(), $metadataBackupJobId);

        $this->messageBus->dispatch(new MetadataRestoreTask($job));

        return $job;
    }

    /**
     * @throws \Exception
     */
    protected function removeItem(mixed $identifier, mixed $job, array $filters): void
    {
        assert($job instanceof MetadataRestoreJob);
        $backupJob = $job;

        $this->blobService->removeMetadataRestoreJob($backupJob);
    }
}
