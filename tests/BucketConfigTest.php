<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Tests;

use Dbp\Relay\BlobBundle\Configuration\BucketConfig;
use Dbp\Relay\BlobBundle\DependencyInjection\Configuration as BlobConfiguration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Processor;

class BucketConfigTest extends TestCase
{
    private const TYPES = [
        'generic_id_card' => [
            'json_schema_path' => '/tmp/generic_id_card.json',
            'verity_profile' => null,
        ],
    ];

    public function testTypesConfigIsLoaded(): void
    {
        $bucketConfig = BucketConfig::fromConfig($this->createBucketConfig([
            'types' => self::TYPES,
        ]));

        $this->assertSame(self::TYPES, $bucketConfig->getTypes());
    }

    public function testAdditionalTypesConfigIsNormalizedToTypes(): void
    {
        $processor = new Processor();
        $config = $processor->processConfiguration(new BlobConfiguration(), [
            $this->createBundleConfig($this->createBucketConfig([
                'additional_types' => self::TYPES,
            ])),
        ]);

        $this->assertSame(self::TYPES, $config['buckets'][0]['types']);
        $this->assertArrayNotHasKey('additional_types', $config['buckets'][0]);
    }

    private function createBundleConfig(array $bucketConfig): array
    {
        return [
            'database_url' => 'sqlite:///:memory:',
            'reporting_interval' => '0 9 * * MON',
            'quota_warning_interval' => '0 6 * * *',
            'cleanup_interval' => '0 * * * *',
            'file_integrity_checks' => true,
            'additional_auth' => false,
            'filedata_schema' => '/tmp/filedata-v1.schema.json',
            'integrity_check_interval' => '0 0 1 * *',
            'bucket_size_check_interval' => '0 2 * * 1',
            'metadata_size_limit' => 1000000,
            'buckets' => [$bucketConfig],
        ];
    }

    private function createBucketConfig(array $overrides = []): array
    {
        return array_replace([
            'service' => 'Dbp\Relay\BlobBundle\TestUtils\TestDatasystemProviderService',
            'internal_bucket_id' => '018e0ed8-e6d7-794f-8f60-42efe27ef49e',
            'bucket_id' => 'test-bucket',
            'key' => '08d848fd868d83646778b87dd0695b10f59c78e23b286e9884504d1bb43cce93',
            'quota' => 500,
            'output_validation' => true,
            'notify_when_quota_over' => 70,
            'report_when_expiry_in' => 'P62D',
            'bucket_owner' => 'office@example.com',
            'link_expire_time' => 'PT1M',
        ], $overrides);
    }
}
