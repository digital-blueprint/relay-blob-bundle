<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\TestUtils;

use Dbp\Relay\BlobBundle\Api\FileApi;
use Dbp\Relay\BlobBundle\Configuration\ConfigurationService;
use Dbp\Relay\BlobBundle\Service\BlobService;
use Dbp\Relay\BlobBundle\Service\DatasystemProviderService;
use Dbp\Relay\BlobLibrary\Api\BlobApi;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;

class BlobTestUtils
{
    public static function createTestEntityManager(ContainerInterface $container): TestEntityManager
    {
        return new TestEntityManager($container);
    }

    public static function createTestBlobService(EntityManagerInterface $entityManager, ?array $testConfig = null,
        ?EventSubscriberInterface $eventSubscriber = null): BlobService
    {
        $eventDispatcher = new EventDispatcher();
        if ($eventSubscriber !== null) {
            $eventDispatcher->addSubscriber($eventSubscriber);
        }

        $configurationService = new ConfigurationService();
        $configurationService->setConfig($testConfig ?? self::getTestConfig());

        $datasystemProviderService = new DatasystemProviderService();
        $datasystemProviderService->addService(new TestDatasystemProviderService());

        $blobService = new BlobService($entityManager, $configurationService, $datasystemProviderService, $eventDispatcher);
        $blobService->setLogger(new NullLogger());

        return $blobService;
    }

    public static function createLocalModeBlobApi(string $bucketIdentifier, EntityManagerInterface $entityManager,
        ?array $testConfig = null, ?EventSubscriberInterface $eventSubscriber = null, ?RequestStack $requestStack = null): BlobApi
    {
        $requestStack ??= new RequestStack();
        $fileApi = new FileApi(self::createTestBlobService($entityManager, $testConfig, $eventSubscriber), $requestStack);

        return BlobApi::createFromBlobFileApi($bucketIdentifier, $fileApi);
    }

    public static function tearDown(): void
    {
        TestDatasystemProviderService::cleanup();
    }

    public static function getTestConfig(): array
    {
        return [
            'database_url' => 'sqlite:///:memory:',
            'reporting_interval' => '0 9 * * MON',
            'cleanup_interval' => '0 * * * *',
            'file_integrity_checks' => false,
            'additional_auth' => false,
            'integrity_check_interval' => '0 0 1 * *',
            'bucket_size_check_interval' => '0 2 * * 1',
            'quota_warning_interval' => '0 6 * * *',
            'buckets' => [
                [
                    'service' => 'Dbp\Relay\BlobBundle\TestUtils\TestDatasystemProviderService',
                    'internal_bucket_id' => '018e0ed8-e6d7-794f-8f60-42efe27ef49e',
                    'bucket_id' => 'test-bucket',
                    'key' => '08d848fd868d83646778b87dd0695b10f59c78e23b286e9884504d1bb43cce93',
                    'quota' => 500, // in MB
                    'output_validation' => true,
                    'notify_when_quota_over' => 70, // in percent of quota
                    'report_when_expiry_in' => 'P62D', // in Days, 62 = two 31 day months
                    'bucket_owner' => 'manuel.kocher@tugraz.at',
                    'link_expire_time' => 'PT1M',
                    'reporting' => [
                        'dsn' => 'smtp:localhost',
                        'from' => 'noreply@email.com',
                        'to' => 'office@email.com',
                        'subject' => 'Blob file deletion reporting',
                        'html_template' => 'emails/reporting.html.twig',
                    ],
                    'integrity' => [
                        'dsn' => 'smtp:localhost',
                        'from' => 'noreply@email.com',
                        'to' => 'office@email.com',
                        'subject' => 'Blob file integrity check report',
                        'html_template' => 'emails/integrity.html.twig',
                    ],
                ],
                [
                    'service' => 'Dbp\Relay\BlobBundle\TestUtils\TestDatasystemProviderService',
                    'internal_bucket_id' => '018e1902-c4b6-7e9a-9488-084daf6b3218',
                    'bucket_id' => 'test-bucket-2',
                    'key' => 'f5b08061e9989d0357c4173aa3af9bc05d0400121af5f90a43e6cdb91ff1fbf2',
                    'quota' => 500, // in MB
                    'output_validation' => true,
                    'notify_when_quota_over' => 70, // in percent of quota
                    'report_when_expiry_in' => 'P62D', // in Days, 62 = two 31 day months
                    'bucket_owner' => 'manuel.kocher@tugraz.at',
                    'link_expire_time' => 'PT1M',
                    'reporting' => [
                        'dsn' => 'smtp:localhost',
                        'from' => 'noreply@email.com',
                        'to' => 'office@email.com',
                        'subject' => 'Blob file deletion reporting',
                        'html_template' => 'emails/reporting.html.twig',
                    ],
                ],
            ],
        ];
    }
}
