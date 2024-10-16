<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Entity;

use Ramsey\Uuid\Validator\GenericValidator;

class Bucket
{
    private string $identifier = '';

    private string $service = '';
    private string $id = '';
    private string $reportExpiryWhenIn = '';
    private string $key = '';
    private int $quota = 0;
    private string $linkExpireTime = '';
    private ?array $warnQuotaOverConfig = null;
    private ?array $reportingConfig = null;
    private ?array $integrityCheckConfig = null;
    private int $notifyWhenQuotaOver = 0;
    private ?array $additionalTypes = null;

    private bool $outputValidation = true;
    private ?array $bucketSizeConfig = null;

    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    public function setIdentifier(string $identifier): void
    {
        $this->identifier = $identifier;
    }

    public function getService(): string
    {
        return $this->service;
    }

    public function setService(string $service): void
    {
        $this->service = $service;
    }

    public function getBucketID(): string
    {
        return $this->id;
    }

    public function setBucketID(string $id): void
    {
        $this->id = $id;
    }

    public function getReportExpiryWhenIn(): string
    {
        return $this->reportExpiryWhenIn;
    }

    public function setReportExpiryWhenIn(string $reportExpiryWhenIn): void
    {
        $this->reportExpiryWhenIn = $reportExpiryWhenIn;
    }

    public function setNotifyWhenQuotaOver(int $notifyWhenQuotaOver): void
    {
        $this->notifyWhenQuotaOver = $notifyWhenQuotaOver;
    }

    public function getNotifyWhenQuotaOver(): int
    {
        return $this->notifyWhenQuotaOver;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function setKey(string $key): void
    {
        $this->key = $key;
    }

    public function getQuota(): int
    {
        return $this->quota;
    }

    public function setQuota(int $quota): void
    {
        $this->quota = $quota;
    }

    public function setOutputValidation(bool $bool): void
    {
        $this->outputValidation = $bool;
    }

    public function getOutputValidation(): bool
    {
        return $this->outputValidation;
    }

    public function getLinkExpireTime(): string
    {
        return $this->linkExpireTime;
    }

    public function setLinkExpireTime(string $linkExpireTime): void
    {
        $this->linkExpireTime = $linkExpireTime;
    }

    public function getWarnQuotaOverConfig(): ?array
    {
        return $this->warnQuotaOverConfig;
    }

    public function setWarnQuotaOverConfig(?array $warnQuotaOverConfig): self
    {
        $this->warnQuotaOverConfig = $warnQuotaOverConfig;

        return $this;
    }

    public function getReportingConfig(): ?array
    {
        return $this->reportingConfig;
    }

    public function setReportingConfig(?array $reportingConfig): self
    {
        $this->reportingConfig = $reportingConfig;

        return $this;
    }

    public function getIntegrityCheckConfig(): ?array
    {
        return $this->integrityCheckConfig;
    }

    public function setIntegrityCheckConfig(?array $integrityCheckConfig): self
    {
        $this->integrityCheckConfig = $integrityCheckConfig;

        return $this;
    }

    public function getBucketSizeConfig(): ?array
    {
        return $this->bucketSizeConfig;
    }

    public function setBucketSizeConfig(?array $bucketSizeConfig): self
    {
        $this->bucketSizeConfig = $bucketSizeConfig;

        return $this;
    }

    public function getAdditionalTypes(): array
    {
        return $this->additionalTypes ?? [];
    }

    public function setAdditionalTypes(?array $additionalTypes): self
    {
        $this->additionalTypes = $additionalTypes;

        return $this;
    }

    public static function fromConfig(array $config): Bucket
    {
        $bucket = new Bucket();
        if ($config['internal_bucket_id'] && !(new GenericValidator())->validate((string) $config['internal_bucket_id'])) {
            throw new \RuntimeException(sprintf('the config entry internal_bucket_id is no valid uuid for bucket \'%s\'', (string) $config['internal_bucket_id']));
        }
        $bucket->setIdentifier((string) $config['internal_bucket_id']);
        $bucket->setService((string) $config['service']);
        $bucket->setBucketID((string) $config['bucket_id']);
        $bucket->setNotifyWhenQuotaOver((int) $config['notify_when_quota_over']);
        $bucket->setReportExpiryWhenIn((string) $config['report_when_expiry_in']);
        $bucket->setKey((string) $config['key']);
        $bucket->setQuota((int) $config['quota']);
        $bucket->setOutputValidation((bool) $config['output_validation']);
        $bucket->setLinkExpireTime((string) $config['link_expire_time']);

        if (
            array_key_exists('warn_quota', $config)
            && is_array($config['warn_quota'])
            && !empty($config['warn_quota']['dsn'])
            && !empty($config['warn_quota']['from'])
            && !empty($config['warn_quota']['to'])
            && !empty($config['warn_quota']['subject'])
            && !empty($config['warn_quota']['html_template'])
        ) {
            $bucket->setWarnQuotaOverConfig($config['warn_quota']);
        }
        if (
            array_key_exists('reporting', $config)
            && is_array($config['reporting'])
            && !empty($config['reporting']['dsn'])
            && !empty($config['reporting']['from'])
            && !empty($config['reporting']['to'])
            && !empty($config['reporting']['subject'])
            && !empty($config['reporting']['html_template'])
        ) {
            $bucket->setReportingConfig($config['reporting']);
        }

        if (
            array_key_exists('integrity', $config)
            && is_array($config['integrity'])
            && !empty($config['integrity']['dsn'])
            && !empty($config['integrity']['from'])
            && !empty($config['integrity']['to'])
            && !empty($config['integrity']['subject'])
            && !empty($config['integrity']['html_template'])
        ) {
            $bucket->setIntegrityCheckConfig($config['integrity']);
        }

        if (
            array_key_exists('bucket_size', $config)
            && is_array($config['bucket_size'])
            && !empty($config['bucket_size']['dsn'])
            && !empty($config['bucket_size']['from'])
            && !empty($config['bucket_size']['to'])
            && !empty($config['bucket_size']['subject'])
            && !empty($config['bucket_size']['html_template'])
        ) {
            $bucket->setBucketSizeConfig($config['bucket_size']);
        }

        if (
            array_key_exists('additional_types', $config)
            && is_array($config['additional_types'])
        ) {
            $bucket->setAdditionalTypes(array_merge(...$config['additional_types']));
        }

        return $bucket;
    }
}
