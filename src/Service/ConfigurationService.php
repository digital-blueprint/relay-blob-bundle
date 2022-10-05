<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Service;

use Dbp\Relay\BlobBundle\Entity\Bucket;
use Dbp\Relay\MonoBundle\Entity\PaymentContract;
use Dbp\Relay\MonoBundle\Entity\PaymentMethod;
use Dbp\Relay\MonoBundle\Entity\PaymentType;
use Symfony\Component\HttpFoundation\UrlHelper;
use Symfony\Contracts\Translation\TranslatorInterface;

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

    /**
     * @return array
     */
    public function getConfig()
    {
        return $this->config;
    }

    /**
     * @return Bucket[]
     */
    public function getBuckets(): array
    {
        $buckets = [];

        $bucketsConfig = $this->config['buckets'];
        foreach ($bucketsConfig as $bucket => $bucketConfig) {
            $bucket = Bucket::fromConfig($bucket, $bucketConfig);
            $buckets[] = $bucket;
        }

        return $buckets;
    }

    public function getBucketByName(string $bucketName): ?Bucket
    {
        $bucket = null;

        if (array_key_exists($bucketName, $this->config['buckets'])) {
            $bucketConfig = $this->config['buckets'][$bucketName];
            $bucket = Bucket::fromConfig($bucketName, $bucketConfig);
        }

        return $bucket;
    }

    public function getBucketID(string $bucketID): ?Bucket
    {
        $buckets = $this->config['buckets'];
        foreach ($buckets as $bucket => $bucketConfig) {
            if (array_key_exists($bucketID, $bucketConfig['bucket_id'])) {
                return Bucket::fromConfig($bucket, $bucketConfig);
            }
        }

        return null;
    }
}
