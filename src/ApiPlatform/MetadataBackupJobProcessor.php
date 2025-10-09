<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\ApiPlatform;

use Dbp\Relay\BlobBundle\Authorization\AuthorizationService;
use Dbp\Relay\BlobBundle\Entity\MetadataBackupJob;
use Dbp\Relay\BlobBundle\Service\BlobService;
use Dbp\Relay\BlobBundle\Service\DatasystemProviderService;
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
        private readonly BlobService $blobService, private readonly DatasystemProviderService $datasystemProviderService, private readonly AuthorizationService $authService)
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
        $internalId = $filters['bucketIdentifier'];
        if ($internalId === null) {
            throw ApiError::withDetails(
                Response::HTTP_BAD_REQUEST,
                'Bucket could not be found!',
                'blob:bucket-not-found'
            );
        }

        $this->authService->checkCanAccessMetadataBackup($internalId);

        $job->setIdentifier(Uuid::v7()->toRfc4122());
        $job->setBucketId($internalId);
        $job->setStatus(MetadataBackupJob::JOB_STATUS_RUNNING);
        $job->setStarted((new \DateTimeImmutable('now'))->format('c'));
        $job->setFinished(null);
        $job->setErrorId(null);
        $job->setErrorMessage(null);
        $job->setHash(null);
        $job->setFileRef(null);

        $this->blobService->saveMetadataBackupJob($job);
        try {
            $this->blobService->startMetadataBackup($job);
        } catch (ApiError $e) {
            $job->setStatus(MetadataBackupJob::JOB_STATUS_ERROR);
            throw ApiError::withDetails($e->getStatusCode(), $e->getMessage(), $e->getErrorId());
        } catch (\Exception $e) {
            $job->setStatus(MetadataBackupJob::JOB_STATUS_ERROR);
            throw ApiError::withDetails(Response::HTTP_INTERNAL_SERVER_ERROR, 'Something went wrong!');
        }

        if ($this->blobService->getMetadataBackupJobById($job->getIdentifier())->getStatus() === MetadataBackupJob::JOB_STATUS_CANCELLED) {
            return $this->blobService->getMetadataBackupJobById($job->getIdentifier());
        }
        $service = $this->datasystemProviderService->getService($this->blobService->getBucketConfigByInternalBucketId($this->blobService->getInternalBucketIdByBucketID($filters['bucketIdentifier']))->getService());
        $job->setStatus(MetadataBackupJob::JOB_STATUS_FINISHED);
        $job->setFinished((new \DateTimeImmutable('now'))->format('c'));
        $job->setHash($service->getMetadataBackupFileHash($internalId));
        $job->setFileRef($service->getMetadataBackupFileRef($internalId));
        $this->blobService->saveMetadataBackupJob($job);

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
