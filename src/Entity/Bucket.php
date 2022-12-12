<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Entity;

use Dbp\Relay\BlobBundle\Helper\PoliciesStruct;

class Bucket
{
    /**
     * @var string
     */
    private $identifier;

    /**
     * @var string
     */
    private $service;

    /**
     * @var string
     */
    private $name;

    /**
     * @var string
     */
    private $publicKey;

    /**
     * @var string
     */
    private $path;

    /**
     * @var int
     */
    private $quota;

    /**
     * @var string
     */
    private $max_retention_duration;

    /**
     * @var string
     */
    private $linkExpireTime;

    /**
     * @var PoliciesStruct
     */
    private $policies;

    /**
     * @var ?array
     */
    private $notifyQuotaConfig;

    /**
     * @var ?array
     */
    private $reportingConfig;

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

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getPublicKey(): string
    {
        return $this->publicKey;
    }

    public function setPublicKey(string $publicKey): void
    {
        $this->publicKey = $publicKey;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function setPath(string $path): void
    {
        $this->path = $path;
    }

    public function getQuota(): int
    {
        return $this->quota;
    }

    public function setQuota(int $quota): void
    {
        $this->quota = $quota;
    }

    public function getMaxRetentionDuration(): string
    {
        return $this->max_retention_duration;
    }

    public function setMaxRetentionDuration(string $max_retention_duration): void
    {
        $this->max_retention_duration = $max_retention_duration;
    }

    public function getLinkExpireTime(): string
    {
        return $this->linkExpireTime;
    }

    public function setLinkExpireTime(string $linkExpireTime): void
    {
        $this->linkExpireTime = $linkExpireTime;
    }

    public function getPolicies(): PoliciesStruct
    {
        return $this->policies;
    }

    public function setPolicies(PoliciesStruct $policies): void
    {
        $this->policies = $policies;
    }

    public function getNotifyQuotaConfig(): ?array
    {
        return $this->notifyQuotaConfig;
    }

    public function setNotifyQuotaConfig(?array $notifyQuotaConfig): self
    {
        $this->notifyQuotaConfig = $notifyQuotaConfig;

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


    public static function fromConfig(array $config): Bucket
    {
        $bucket = new Bucket();
        $bucket->setIdentifier((string) $config['bucket_id']);
        $bucket->setService((string) $config['service']);
        $bucket->setName((string) $config['bucket_name']);
        $bucket->setPublicKey((string) $config['public_key']);
        $bucket->setPath((string) $config['path']);
        $bucket->setQuota((int) $config['quota']);
        $bucket->setMaxRetentionDuration((string) $config['max_retention_duration']);
        $bucket->setLinkExpireTime((string) $config['link_expire_time']);

        $policies = PoliciesStruct::withPoliciesArray($config['policies']);

        $bucket->setPolicies($policies);

        if (
            array_key_exists('notify_quota', $config)
            && is_array($config['notify_quota'])
            && !empty($config['notify_quota']['dsn'])
            && !empty($config['notify_quota']['from'])
            && !empty($config['notify_quota']['to'])
            && !empty($config['notify_quota']['subject'])
            && !empty($config['notify_quota']['html_template'])
        ) {
            $bucket->setNotifyQuotaConfig($config['notify_quota']);
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

        return $bucket;
    }

    /**
     * @return string
     */
    public function __toString()
    {
        return $this->identifier;
    }
}
