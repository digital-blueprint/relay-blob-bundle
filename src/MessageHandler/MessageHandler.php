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

#[AsMessageHandler(handles: MetadataRestoreTask::class)]
#[AsMessageHandler(handles: MetadataBackupTask::class)]
class MessageHandler
{
    private BlobService $blobService;

    public function __invoke(MetadataBackupTask|MetadataRestoreTask $message)
    {
        if ($message instanceof MetadataBackupTask) {
            $this->handleBackupTask($message);
        }
        if ($message instanceof MetadataRestoreTask) {
            $this->handleRestoreTask($message);
        }
    }

    public function __construct(BlobService $blobService)
    {
        $this->blobService = $blobService;
    }

    public function handleBackupTask(MetadataBackupTask $task): void
    {
        $job = $task->getJob();
        // get job again, otherwise doctrine is confused because its a different EM between sync and async
        $job = $this->blobService->getMetadataBackupJobById($job->getIdentifier());
        $internalId = $job->getBucketId();
        try {
            $this->blobService->startMetadataBackup($job);
            // get job again, otherwise doctrine is confused because its a different EM between sync and async
            // also, map was cleared beforehand!
            $job = $this->blobService->getMetadataBackupJobById($job->getIdentifier());
        } catch (\Exception $e) {
            // get job again, otherwise doctrine is confused because its a different EM between sync and async
            // also, map was cleared beforehand!
            $job = $this->blobService->getMetadataBackupJobById($job->getIdentifier());
            $job->setStatus(MetadataBackupJob::JOB_STATUS_ERROR);
            $job->setErrorMessage($e->__toString());
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

    public function handleRestoreTask(MetadataRestoreTask $task): void
    {
        $job = $task->getJob();
        $internalId = $job->getBucketId();
        try {
            $this->blobService->deleteBucketByInternalBucketId($internalId); // this deletes the ORM identity map!
            // get job again, otherwise doctrine is confused because its a different EM between sync and async
            // also, map was cleared beforehand!
            $job = $this->blobService->getMetadataRestoreJobById($job->getIdentifier());
            $this->blobService->startMetadataRestore($job);
            // get job again, otherwise doctrine is confused because its a different EM between sync and async
            // also, map was cleared beforehand!
            $job = $this->blobService->getMetadataRestoreJobById($job->getIdentifier());
        } catch (\Exception $e) {
            // get job again, otherwise doctrine is confused because its a different EM between sync and async
            // also, map was cleared beforehand!
            $job = $this->blobService->getMetadataRestoreJobById($job->getIdentifier());
            $job->setStatus(MetadataRestoreJob::JOB_STATUS_ERROR);
            $job->setErrorMessage($e->__toString());
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
