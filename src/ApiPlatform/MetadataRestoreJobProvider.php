<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\ApiPlatform;

use Dbp\Relay\BlobBundle\Authorization\AuthorizationService;
use Dbp\Relay\BlobBundle\Entity\FileData;
use Dbp\Relay\BlobBundle\Entity\MetadataRestoreJob;
use Dbp\Relay\BlobBundle\Service\BlobService;
use Dbp\Relay\CoreBundle\Exception\ApiError;
use Dbp\Relay\CoreBundle\Rest\AbstractDataProvider;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\HttpFoundation\Response;

/**
 * @internal
 *
 * @extends AbstractDataProvider<FileData>
 */
class MetadataRestoreJobProvider extends AbstractDataProvider implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    public function __construct(
        private readonly BlobService $blobService,
        private readonly AuthorizationService $authService)
    {
        parent::__construct();
    }

    protected function requiresAuthentication(int $operation): bool
    {
        return true;
    }

    /**
     * @throws \JsonException
     */
    protected function getItemById(string $id, array $filters = [], array $options = []): ?MetadataRestoreJob
    {
        return $this->getMetadataRestoreJobById($id, $filters);
    }

    /**
     * @throws \JsonException
     * @throws \Exception
     */
    protected function getMetadataRestoreJobById(string $id, array $filters): object
    {
        $this->authService->checkCanAccessMetadataBackup();
        $backupJob = $this->blobService->getMetadataRestoreJobById($id);
        if ($backupJob === null) {
            throw ApiError::withDetails(
                Response::HTTP_NOT_FOUND,
                'The metadata backup job with the given id cannot be found!',
                'blob:metadata-backup-job-not-found'
            );
        }

        return $backupJob;
    }

    /**
     * @throws \Exception
     */
    protected function getPage(int $currentPageNumber, int $maxNumItemsPerPage, array $filters = [], array $options = []): array
    {
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
        $this->authService->checkCanAccessMetadataBackup();
        $internalId = $this->blobService->getInternalBucketIdByBucketID($bucketIdentifier);
        if ($internalId === null) {
            throw ApiError::withDetails(
                Response::HTTP_BAD_REQUEST,
                'Bucket could not be found!',
                'blob:bucket-not-found'
            );
        }
        $backupJobs = $this->blobService->getMetadataRestoreJobsByInternalBucketId($internalId);
        if (empty($backupJobs)) {
            throw ApiError::withDetails(
                Response::HTTP_NOT_FOUND,
                'No metadata backup jobs cannot be found for the given bucket id!',
                'blob:metadata-backup-jobs-not-found'
            );
        }

        return $backupJobs;
    }
}
