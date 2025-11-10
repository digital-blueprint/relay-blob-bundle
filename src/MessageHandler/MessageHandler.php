<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\MessageHandler;

use Dbp\Relay\BlobBundle\Entity\MetadataBackupJob;
use Dbp\Relay\BlobBundle\Entity\MetadataRestoreJob;
use Dbp\Relay\BlobBundle\Service\BlobService;
use Dbp\Relay\BlobBundle\Task\MetadataBackupTask;
use Dbp\Relay\BlobBundle\Task\MetadataRestoreTask;
use Dbp\Relay\CoreBundle\Exception\ApiError;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

class MessageHandler
{
    private BlobService $blobService;

    public function __construct(BlobService $blobService)
    {
        $this->blobService = $blobService;
    }

    #[AsMessageHandler]
    public function handleBackupTask(MetadataBackupTask $task): void
    {
        $job = $task->getJob();
        $internalId = $job->getBucketId();
        try {
            $this->blobService->startMetadataBackup($job);
        } catch (\Exception $e) {
            $job->setStatus(MetadataBackupJob::JOB_STATUS_ERROR);
            $job->setErrorMessage($e->getMessage());
            if ($e instanceof ApiError) {
                $job->setErrorId($e->getErrorId());
                $this->blobService->finishAndSaveMetadataBackupJob($job, $internalId);
                throw ApiError::withDetails($e->getStatusCode(), $job->getErrorMessage(), $job->getErrorId());
            } else {
                $this->blobService->finishAndSaveMetadataBackupJob($job, $internalId);
                throw ApiError::withDetails(Response::HTTP_INTERNAL_SERVER_ERROR, 'Something went wrong!');
            }
        }

        $this->blobService->finishAndSaveMetadataBackupJob($job, $internalId);
        $this->blobService->deleteFinishedMetadataBackupJobsExceptGivenOneByInternalBucketId($job->getBucketId(), $job->getIdentifier()); // delete other FINISHED job afterwards in case of an error
    }

    #[AsMessageHandler]
    public function handleRestoreTask(MetadataRestoreTask $task): void
    {
        $job = $task->getJob();
        $internalId = $job->getBucketId();
        try {
            $this->blobService->deleteBucketByInternalBucketId($internalId);
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

        $this->blobService->finishAndSaveMetadataRestoreJob($job, $internalId);
        $this->blobService->deleteFinishedMetadataRestoreJobsExceptGivenOneByInternalBucketId($job->getBucketId(), $job->getIdentifier()); // delete other FINISHED job afterwards in case of an error
    }
}
