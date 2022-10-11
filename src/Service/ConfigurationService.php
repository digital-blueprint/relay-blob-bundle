<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Service;

use Dbp\Relay\BlobBundle\Entity\Bucket;
use Symfony\Component\HttpFoundation\UrlHelper;

class ConfigurationService
{
    /**
     * @var array
     */
    private $config = [];

    /**
     * @var UrlHelper
     */
    private $urlHelper;

    public function __construct(
        UrlHelper $urlHelper
    ) {
        $this->urlHelper = $urlHelper;
    }

    /**
     * @return void
     */
    public function setConfig(array $config)
    {
        $this->config = $config;
    }

    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * @return Bucket[]
     */
    public function getBuckets(): array
    {
        $buckets = [];

        $bucketsConfig = $this->config;

        if (!$bucketsConfig["buckets"])
            return $buckets;

        foreach ($bucketsConfig["buckets"] as $bucketConfig) {
            $bucket = Bucket::fromConfig($bucketConfig);
            $buckets[] = $bucket;
        }

        return $buckets;
    }

    public function getBucketByName(string $bucketName): ?Bucket
    {
        $bucket = null;

        if (array_key_exists($bucketName, $this->config['buckets'])) {
            $bucketConfig = $this->config['buckets'][$bucketName];
            $bucket = Bucket::fromConfig($bucketConfig);
        }

        return $bucket;
    }

    public function getBucketByID(string $bucketID): ?Bucket
    {
        $buckets = $this->config['buckets'];

        foreach ($buckets as $bucket => $bucketConfig) {
            if ($bucketID == $bucketConfig['bucket_id']) {
                return Bucket::fromConfig($bucketConfig);
            }
        }

        return null;
    }
}
