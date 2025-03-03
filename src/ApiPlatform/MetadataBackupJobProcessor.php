<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\ApiPlatform;

use Dbp\Relay\BlobBundle\Entity\MetadataBackupJob;
use Dbp\Relay\BlobBundle\Service\BlobService;
use Dbp\Relay\CoreBundle\Exception\ApiError;
use Dbp\Relay\CoreBundle\Rest\AbstractDataProcessor;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * @internal
 */
class MetadataBackupJobProcessor extends AbstractDataProcessor
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

    protected function addItem(mixed $data, array $filters): MetadataBackupJob
    {
        assert($data instanceof MetadataBackupJob);
        $job = $data;

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

        $job->setIdentifier(Uuid::v7()->toRfc4122());
        $job->setBucketId($filters['bucketIdentifier']);
        $job->setStatus(MetadataBackupJob::JOB_STATUS_RUNNING);
        $job->setStarted((new \DateTimeImmutable('now'))->format('c'));
        $job->setFinished(null);
        $job->setErrorId(null);
        $job->setErrorMessage(null);
        $job->setHash(null);
        $job->setFileRef(null);

        $this->blobService->addMetadataBackupJob($job);

        try {
            $this->blobService->startMetadataBackup($job);
        } catch (ApiError $e) {
            throw ApiError::withDetails($e->getStatusCode(), $e->getMessage(), $e->getErrorId());
        } catch (\Exception $e) {
            throw ApiError::withDetails(Response::HTTP_INTERNAL_SERVER_ERROR, 'Something went wrong!');
        }

        return $job;
    }

    protected function updateItem(mixed $identifier, mixed $data, mixed $previousData, array $filters): MetadataBackupJob
    {
        assert($data instanceof MetadataBackupJob);
        assert($previousData instanceof MetadataBackupJob);
        $job = $data;
        $previousJob = $previousData;

        // return $this->blobService->updateFile($backupJob, $previousBackupJob);
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
