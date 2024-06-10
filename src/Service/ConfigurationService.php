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
    public function setConfig(array $config): void
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
     * Returns the bucket object for the given bucketID.
     */
    public function getInternalBucketIdByBucketID(string $bucketID): ?string
    {
        $buckets = $this->config['buckets'];

        foreach ($buckets as $bucket => $bucketConfig) {
            if ($bucketID === $bucketConfig['bucket_id']) {
                return $bucketConfig['internal_bucket_id'];
            }
        }

        return null;
    }

    /**
     * Returns the bucket object for the given internalBucketID.
     */
    public function getBucketByInternalID(string $internalBucketID): ?Bucket
    {
        $buckets = $this->config['buckets'];

        foreach ($buckets as $bucket => $bucketConfig) {
            if ($internalBucketID === $bucketConfig['internal_bucket_id']) {
                return Bucket::fromConfig($bucketConfig);
            }
        }

        return null;
    }

    /**
     * Gets the reporting interval from the config.
     */
    public function getReportingInterval(): mixed
    {
        return $this->config['reporting_interval'];
    }

    /**
     * Gets the reporting interval from the config.
     */
    public function getQuotaWarningInterval(): mixed
    {
        return $this->config['quota_warning_interval'];
    }

    /**
     * Gets the cleanup interval from the config.
     */
    public function getCleanupInterval(): mixed
    {
        return $this->config['cleanup_interval'];
    }

    public function getOutputValidationForBucketId($bucketId): mixed
    {
        $buckets = $this->config['buckets'];

        foreach ($buckets as $bucket => $bucketConfig) {
            if ($bucketId === $bucketConfig['bucket_id']) {
                return $bucketConfig['output_validation'];
            }
        }

        return null;
    }

    public function doFileIntegrityChecks(): bool
    {
        return $this->config['file_integrity_checks'];
    }

    public function checkAdditionalAuth(): bool
    {
        return $this->config['additional_auth'];
    }

    public function getIntegrityCheckInterval(): string
    {
        return $this->config['integrity_check_interval'];
    }

    public function getBucketSizeCheckInterval()
    {
        return $this->config['bucket_size_check_interval'];
    }
}
