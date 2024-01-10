<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Service;

use Dbp\Relay\BlobBundle\Entity\Bucket;

class ConfigurationService
{
    /**
     * @var array
     */
    private $config = [];

    public function __construct()
    {
    }

    /**
     * Sets the config.
     */
    public function setConfig(array $config)
    {
        $this->config = $config;
    }

    /**
     * Returns the config.
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * Gets all configured bucket objects.
     *
     * @return Bucket[]
     */
    public function getBuckets(): array
    {
        $buckets = [];

        $bucketsConfig = $this->config;

        if (!$bucketsConfig['buckets']) {
            return $buckets;
        }

        foreach ($bucketsConfig['buckets'] as $bucketConfig) {
            $bucket = Bucket::fromConfig($bucketConfig);
            $buckets[] = $bucket;
        }

        return $buckets;
    }

    /**
     * Returns the bucket object for the given bucket name.
     */
    public function getBucketByName(string $bucketName): ?Bucket
    {
        $bucket = null;

        if (array_key_exists($bucketName, $this->config['buckets'])) {
            $bucketConfig = $this->config['buckets'][$bucketName];
            $bucket = Bucket::fromConfig($bucketConfig);
        }

        return $bucket;
    }

    /**
     * Returns the bucket object for the given bucketID.
     */
    public function getBucketByID(string $bucketID): ?Bucket
    {
        $buckets = $this->config['buckets'];

        foreach ($buckets as $bucket => $bucketConfig) {
            if ($bucketID === $bucketConfig['bucket_id']) {
                return Bucket::fromConfig($bucketConfig);
            }
        }

        return null;
    }

    /**
     * Gets the reporting interval from the config.
     *
     * @return mixed
     */
    public function getReportingInterval()
    {
        return $this->config['reporting_interval'];
    }

    /**
     * Gets the cleanup interval from the config.
     *
     * @return mixed
     */
    public function getCleanupInterval()
    {
        return $this->config['cleanup_interval'];
    }

    public function doFileIntegrityChecks(): bool
    {
        return $this->config['file_integrity_checks'];
    }

    public function getIntegrityCheckInterval(): string
    {
        return $this->config['integrity_check_interval'];
    }
}
