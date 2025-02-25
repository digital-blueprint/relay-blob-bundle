<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Service;

use Dbp\Relay\BlobBundle\Configuration\ConfigurationService;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;

class BlobUpdates
{
    public function __construct(
        private readonly ConfigurationService $configurationService,
        private readonly BlobService $blobService)
    {
    }

    /**
     * @throws \Exception
     */
    public function updateBucketSizes(?OutputInterface $out = null, $intBucketId = null)
    {
        $buckets = $this->configurationService->getBuckets();
        $someOut = $out ?? new NullOutput();

        foreach ($buckets as $bucket) {
            if (!is_null($intBucketId) && is_string($intBucketId) && $intBucketId !== $bucket->getInternalBucketId()) {
                continue;
            }
            $this->blobService->recalculateAndUpdateBucketSize($intBucketId, $out);
        }
    }
}
