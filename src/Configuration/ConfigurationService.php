<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Configuration;

use Dbp\Relay\BlobBundle\Helper\SchemaValidator;

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

    public function getMetadataSizeLimit(): int
    {
        return $this->config['metadata_size_limit'];
    }

    public function getIntegrityCheckInterval(): string
    {
        return $this->config['integrity_check_interval'];
    }

    public function getBucketSizeCheckInterval()
    {
        return $this->config['bucket_size_check_interval'];
    }

    public function getFiledataSchema(): string
    {
        return $this->config['filedata_schema'];
    }

    public function checkConfig(): void
    {
        // if one bucket config is faulty for some reason, none of the buckets show up
        // thus, we check if some buckets are present
        if (empty($this->getBuckets())) {
            throw new \RuntimeException('No buckets are defined, or one of the bucket configs is invalid');
        }
        // Make sure the global filedata schema exists and is a parseable JSON schema
        SchemaValidator::validateSchema($this->getFiledataSchema());

        foreach ($this->getBuckets() as $bucket) {
            // Make sure the per-type schema files exist and are parseable JSON schemas
            foreach ($bucket->getTypes() as $type) {
                $path = $type['json_schema_path'];
                if ($path === null) {
                    continue;
                }
                SchemaValidator::validateSchema($path);
            }
        }
    }
}
