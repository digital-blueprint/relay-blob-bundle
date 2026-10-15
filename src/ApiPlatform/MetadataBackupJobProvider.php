<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\ApiPlatform;

use Dbp\Relay\BlobBundle\Authorization\AuthorizationService;
use Dbp\Relay\BlobBundle\Entity\FileData;
use Dbp\Relay\BlobBundle\Entity\MetadataBackupJob;
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
class MetadataBackupJobProvider extends AbstractDataProvider implements LoggerAwareInterface
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

    protected function isCurrentUserGrantedOperationAccess(int $operation): bool
    {
        return $this->authService->getCanUse();
    }

    /**
     * @throws \JsonException
     */
    protected function getItemById(string $id, array $filters = [], array $options = []): ?MetadataBackupJob
    {
        return $this->getMetadataBackupJobById($id, $filters);
    }

    /**
     * @throws \JsonException
     * @throws \Exception
     */
    protected function getMetadataBackupJobById(string $id, array $filters): object
    {
        $this->authService->checkCanAccessMetadataBackup();
        $backupJob = $this->blobService->getMetadataBackupJobById($id);
        if ($backupJob === null) {
            throw new ApiError(Response::HTTP_NOT_FOUND, 'The metadata backup job with the given id cannot be found!');
        }

        return $backupJob;
    }

    /**
     * @throws \Exception
     */
    protected function getPage(int $currentPageNumber, int $maxNumItemsPerPage, array $filters = [], array $options = []): array
    {
        $this->authService->checkCanAccessMetadataBackup();
        if (!array_key_exists('bucketIdentifier', $filters)) {
            throw new ApiError(Response::HTTP_BAD_REQUEST, 'Parameter bucketIdentifier is missing');
        }
        $bucketIdentifier = $filters['bucketIdentifier'];
        if ($bucketIdentifier === null) {
            throw new ApiError(Response::HTTP_BAD_REQUEST, 'Parameter bucketIdentifier is missing');
        }
        $internalId = $this->blobService->getInternalBucketIdByBucketID($bucketIdentifier);
        if ($internalId === null) {
            throw new ApiError(Response::HTTP_BAD_REQUEST, 'The specified bucketIdentifier is not configured');
        }
        $backupJobs = $this->blobService->getMetadataBackupJobsByInternalBucketId($internalId, $currentPageNumber, $maxNumItemsPerPage);
        if (empty($backupJobs)) {
            throw new ApiError(Response::HTTP_NOT_FOUND, 'No metadata backup jobs were found for the given bucket id!');
        }

        return $backupJobs;
    }
}
