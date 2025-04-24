<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Configuration;

/**
 * @internal
 */
class ConfigurationService
{
    private array $config = [];

    public function __construct()
    {
    }

    public function setConfig(array $config): void
    {
        $this->config = $config;
    }

    /**
     * Gets all configured bucket objects.
     *
     * @return BucketConfig[]
     */
    public function getBuckets(): array
    {
        $buckets = [];
        foreach ($this->config['buckets'] ?? [] as $bucketConfig) {
            $bucket = BucketConfig::fromConfig($bucketConfig);
            $buckets[] = $bucket;
        }

        return $buckets;
    }

    public function getBucketById(string $bucketID): ?BucketConfig
    {
        foreach ($this->config['buckets'] ?? [] as $bucketConfig) {
            if ($bucketID === $bucketConfig['bucket_id']) {
                return BucketConfig::fromConfig($bucketConfig);
            }
        }

        return null;
    }

    public function getInternalBucketIdByBucketID(string $bucketID): ?string
    {
        foreach ($this->config['buckets'] ?? [] as $bucketConfig) {
            if ($bucketID === $bucketConfig['bucket_id']) {
                return $bucketConfig['internal_bucket_id'];
            }
        }

        return null;
    }

    public function getBucketIdByInternalBucketID(string $internalBucketID): ?string
    {
        foreach ($this->config['buckets'] ?? [] as $bucketConfig) {
            if ($internalBucketID === $bucketConfig['internal_bucket_id']) {
                return $bucketConfig['bucket_id'];
            }
        }

        return null;
    }

    public function getBucketByInternalID(string $internalBucketID): ?BucketConfig
    {
        foreach ($this->config['buckets'] ?? [] as $bucketConfig) {
            if ($internalBucketID === $bucketConfig['internal_bucket_id']) {
                return BucketConfig::fromConfig($bucketConfig);
            }
        }

        return null;
    }

    /**
     * Gets the reporting interval from the config.
     */
    public function getReportingInterval(): string
    {
        return $this->config['reporting_interval'];
    }

    /**
     * Gets the reporting interval from the config.
     */
    public function getQuotaWarningInterval(): string
    {
        return $this->config['quota_warning_interval'];
    }

    /**
     * Gets the cleanup interval from the config.
     */
    public function getCleanupInterval(): string
    {
        return $this->config['cleanup_interval'];
    }

    public function runFileIntegrityHealthchecks(): bool
    {
        return false; // disabled for now, since it doesn't scale for large numbers of files
    }

    public function doFileIntegrityChecks(): bool
    {
        return $this->config['file_integrity_checks'];
    }

    /**
     * TO DISCUSS: maybe always store checksums, independently of the current config setting?
     */
    public function storeFileAndMetadataChecksums(): bool
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

    public function checkConfig(): void
    {
        // Make sure the schema files exist and are valid JSON
        foreach ($this->getBuckets() as $bucket) {
            foreach ($bucket->getAdditionalTypes() as $path) {
                $content = file_get_contents($path);
                if ($content === false) {
                    throw new \RuntimeException('Failed to read: '.$path);
                }
                try {
                    json_decode($content, flags: JSON_THROW_ON_ERROR);
                } catch (\Exception $e) {
                    throw new \RuntimeException('Failed to parse: '.$path.' ('.$e->getMessage().')');
                }
            }
        }
    }
}
