<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\ApiPlatform;

use Dbp\Relay\BlobBundle\Entity\BucketLock;
use Dbp\Relay\BlobBundle\Service\BlobService;
use Dbp\Relay\CoreBundle\Exception\ApiError;
use Dbp\Relay\CoreBundle\Rest\AbstractDataProvider;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\HttpFoundation\Response;

/**
 * @internal
 *
 * @extends AbstractDataProvider<BucketLock>
 */
class BucketLockProvider extends AbstractDataProvider implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    public function __construct(
        private readonly BlobService $blobService
    ) {
        parent::__construct();
    }

    protected function requiresAuthentication(int $operation): bool
    {
        return true;
    }

    /**ss
     * @throws \JsonException
     */
    protected function getItemById(string $id, array $filters = [], array $options = []): ?BucketLock
    {
        return $this->getBucketLockById($id, $filters);
    }

    /**
     * @throws \JsonException
     * @throws \Exception
     */
    protected function getBucketLockById(string $id, array $filters): object
    {
        return $this->blobService->getBucketLock($id);
    }

    /**
     * @throws \Exception
     */
    protected function getPage(int $currentPageNumber, int $maxNumItemsPerPage, array $filters = [], array $options = []): array
    {
        $bucketID = rawurldecode($filters['bucketIdentifier'] ?? '');

        $intBucketId = $this->blobService->getInternalBucketIdByBucketID($bucketID);

        if (!$intBucketId) {
            throw ApiError::withDetails(
                Response::HTTP_NOT_FOUND,
                'bucketIdentifier was not found!',
                'blob:bucket-identifier-not-found'
            );
        }

        return $this->blobService->getBucketLocksByBucketId($bucketID);
    }
}
