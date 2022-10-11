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
     * @var int
     */
    private $max_retention_duration;

    /**
     * @var int
     */
    private $max_idle_retention_duration;

    /**
     * @var PoliciesStruct
     */
    private $policies;

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

    public function getMaxRetentionDuration(): int
    {
        return $this->max_retention_duration;
    }

    public function setMaxRetentionDuration(int $max_retention_duration): void
    {
        $this->max_retention_duration = $max_retention_duration;
    }

    public function getMaxIdleRetentionDuration(): int
    {
        return $this->max_idle_retention_duration;
    }

    public function setMaxIdleRetentionDuration(int $max_idle_retention_duration): void
    {
        $this->max_idle_retention_duration = $max_idle_retention_duration;
    }

    public function getPolicies(): PoliciesStruct
    {
        return $this->policies;
    }

    public function setPolicies(PoliciesStruct $policies): void
    {
        $this->policies = $policies;
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
        $bucket->setMaxRetentionDuration((int) $config['max_retention_duration']);
        $bucket->setMaxIdleRetentionDuration((int) $config['max_idle_retention_duration']);

        $policies = PoliciesStruct::withPoliciesArray($config['policies']);

        $bucket->setPolicies($policies);

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
