<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\ApiPlatform;

use Dbp\Relay\BlobBundle\Configuration\ConfigurationService;
use Dbp\Relay\BlobBundle\Entity\BucketLock;
use Dbp\Relay\BlobBundle\Entity\FileData;
use Dbp\Relay\BlobBundle\Helper\SignatureUtils;
use Dbp\Relay\BlobBundle\Service\BlobService;
use Dbp\Relay\CoreBundle\Exception\ApiError;
use Dbp\Relay\CoreBundle\Rest\AbstractDataProvider;
use Proxies\__CG__\Dbp\Relay\BlobBundle\Entity\Bucket;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
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
        $prefix = rawurldecode($filters['prefix'] ?? '');
        $includeData = ($filters['includeData'] ?? null) === '1';
        $prefixStartsWith = rawurldecode($filters['startsWith'] ?? '');
        $includeDeleteAt = rawurldecode($filters['includeDeleteAt'] ?? '');

        return [];
    }
}
