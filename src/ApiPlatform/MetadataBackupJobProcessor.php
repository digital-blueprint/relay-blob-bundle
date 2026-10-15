<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\ApiPlatform;

use Dbp\Relay\BlobBundle\Authorization\AuthorizationService;
use Dbp\Relay\BlobBundle\Entity\MetadataBackupJob;
use Dbp\Relay\BlobBundle\Service\BlobService;
use Dbp\Relay\BlobBundle\Task\MetadataBackupTask;
use Dbp\Relay\CoreBundle\Exception\ApiError;
use Dbp\Relay\CoreBundle\Rest\AbstractDataProcessor;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * @internal
 */
class MetadataBackupJobProcessor extends AbstractDataProcessor
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
    protected function addItem(mixed $data, array $filters): MetadataBackupJob
    {
        assert($data instanceof MetadataBackupJob);
        $job = $data;

        $this->authService->checkCanAccessMetadataBackup();

        if (!array_key_exists('bucketIdentifier', $filters)) {
            throw new ApiError(Response::HTTP_BAD_REQUEST, 'Parameter bucketIdentifier is missing');
        }
        $bucketIdentifier = $filters['bucketIdentifier'];
        if ($bucketIdentifier === null) {
            throw new ApiError(Response::HTTP_BAD_REQUEST, 'Parameter bucketIdentifier is missing');
        }

        $internalId = $this->blobService->getInternalBucketIdByBucketId($bucketIdentifier);

        if ($internalId === null) {
            throw new ApiError(Response::HTTP_BAD_REQUEST, 'The specified bucketIdentifier is not configured');
        }

        $this->blobService->setupMetadataBackupJob($job, $internalId);
        $this->blobService->saveMetadataBackupJob($job);

        $this->messageBus->dispatch(new MetadataBackupTask($job));

        return $job;
    }

    /**
     * @throws \Exception
     */
    protected function removeItem(mixed $identifier, mixed $job, array $filters): void
    {
        assert($job instanceof MetadataBackupJob);
        $backupJob = $job;

        $this->authService->checkCanAccessMetadataBackup();

        $this->blobService->removeMetadataBackupJob($backupJob);
    }
}
