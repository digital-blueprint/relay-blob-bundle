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
     * @var integer
     */
    private $quota;

    /**
     * @var integer
     */
    private $max_retention_duration;

    /**
     * @var integer
     */
    private $max_idle_retention_duration;

    /**
     * @var PoliciesStruct
     */
    private $policies;

    /**
     * @return string
     */
    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    /**
     * @param string $identifier
     */
    public function setIdentifier(string $identifier): void
    {
        $this->identifier = $identifier;
    }

    /**
     * @return string
     */
    public function getService(): string
    {
        return $this->service;
    }

    /**
     * @param string $service
     */
    public function setService(string $service): void
    {
        $this->service = $service;
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @param string $name
     */
    public function setName(string $name): void
    {
        $this->name = $name;
    }

    /**
     * @return string
     */
    public function getPublicKey(): string
    {
        return $this->publicKey;
    }

    /**
     * @param string $publicKey
     */
    public function setPublicKey(string $publicKey): void
    {
        $this->publicKey = $publicKey;
    }

    /**
     * @return string
     */
    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * @param string $path
     */
    public function setPath(string $path): void
    {
        $this->path = $path;
    }

    /**
     * @return int
     */
    public function getQuota(): int
    {
        return $this->quota;
    }

    /**
     * @param int $quota
     */
    public function setQuota(int $quota): void
    {
        $this->quota = $quota;
    }

    /**
     * @return int
     */
    public function getMaxRetentionDuration(): int
    {
        return $this->max_retention_duration;
    }

    /**
     * @param int $max_retention_duration
     */
    public function setMaxRetentionDuration(int $max_retention_duration): void
    {
        $this->max_retention_duration = $max_retention_duration;
    }

    /**
     * @return int
     */
    public function getMaxIdleRetentionDuration(): int
    {
        return $this->max_idle_retention_duration;
    }

    /**
     * @param int $max_idle_retention_duration
     */
    public function setMaxIdleRetentionDuration(int $max_idle_retention_duration): void
    {
        $this->max_idle_retention_duration = $max_idle_retention_duration;
    }

    /**
     * @return PoliciesStruct
     */
    public function getPolicies(): PoliciesStruct
    {
        return $this->policies;
    }

    /**
     * @param PoliciesStruct $policies
     */
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
        $bucket->setQuota((integer) $config['quota']);
        $bucket->setMaxRetentionDuration((integer) $config['max_retention_duration']);
        $bucket->setMaxIdleRetentionDuration((integer) $config['max_idle_retention_duration']);

        $policies = PoliciesStruct::withPolicies((boolean) $config['creat'], (boolean) $config['open'], (boolean) $config['download'], (boolean) $config['rename'], (boolean) $config['work']);

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
