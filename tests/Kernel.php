<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Tests;

use ApiPlatform\Symfony\Bundle\ApiPlatformBundle;
use Dbp\Relay\BlobBundle\DbpRelayBlobBundle;
use Dbp\Relay\CoreBundle\DbpRelayCoreBundle;
use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Doctrine\Bundle\MigrationsBundle\DoctrineMigrationsBundle;
use Nelmio\CorsBundle\NelmioCorsBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\MonologBundle\MonologBundle;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new SecurityBundle();
        yield new TwigBundle();
        yield new NelmioCorsBundle();
        yield new MonologBundle();
        yield new DoctrineBundle();
        yield new DoctrineMigrationsBundle();
        yield new ApiPlatformBundle();
        yield new DbpRelayBlobBundle();
        yield new DbpRelayCoreBundle();
    }

    protected function configureRoutes(RoutingConfigurator $routes)
    {
        $routes->import('@DbpRelayCoreBundle/Resources/config/routing.yaml');
    }

    protected function configureContainer(ContainerConfigurator $container, LoaderInterface $loader)
    {
        $container->import('@DbpRelayCoreBundle/Resources/config/services_test.yaml');
        $container->import('@DbpRelayBlobBundle/Resources/config/services_test.yaml');
        $container->extension('framework', [
            'test' => true,
            'secret' => '',
        ]);

        $container->extension('api_platform', [
            'metadata_backward_compatibility_layer' => false,
        ]);

        $container->extension('dbp_relay_blob', [
            'database_url' => 'sqlite:///var/dbp_relay_blob_test.db',
            'link_url' => 'http://127.0.0.1:8000/',
            'reporting_interval' => '0 9 * * MON',
            'cleanup_interval' => '0 * * * *',
            'file_integrity_checks' => false,
            'additional_auth' => false,
            'integrity_check_interval' => '0 0 1 * *',
            //            'database_url' => 'sqlite:///:memory:',
            'buckets' => [
                'test_bucket' => [
                    'service' => 'Dbp\Relay\BlobBundle\Tests\DummyFileSystemService',
                    'internal_bucket_id' => '018e0ed8-e6d7-794f-8f60-42efe27ef49e',
                    'bucket_id' => 'test-bucket',
                    'key' => '08d848fd868d83646778b87dd0695b10f59c78e23b286e9884504d1bb43cce93',
                    'quota' => 500, // in MB
                    'notify_when_quota_over' => 70, // in percent of quota
                    'report_when_expiry_in' => 'P62D', // in Days, 62 = two 31 day months
                    'bucket_owner' => 'manuel.kocher@tugraz.at',
                    'max_retention_duration' => 'P1Y',
                    'link_expire_time' => 'PT1M',
                    'notify_quota' => [
                        'dsn' => 'smtp:localhost',
                        'from' => 'noreply@tugraz.at',
                        'to' => 'tamara.steinwender@tugraz.at',
                        'subject' => 'Blob notify quota',
                        'html_template' => 'emails/notify-quota.html.twig',
                    ],
                    'reporting' => [
                        'dsn' => 'smtp:localhost',
                        'from' => 'noreply@tugraz.at',
                        'to' => 'tamara.steinwender@tugraz.at',
                        'subject' => 'Blob file deletion reporting',
                        'html_template' => 'emails/reporting.html.twig',
                    ],
                    'integrity' => [
                        'dsn' => 'smtp:localhost',
                        'from' => 'noreply@tugraz.at',
                        'to' => 'manuel.kocher@tugraz.at',
                        'subject' => 'Blob file integrity check report',
                        'html_template' => 'emails/integrity.html.twig',
                    ],
                ],
                'test_bucket2' => [
                    'service' => 'Dbp\Relay\BlobBundle\Tests\DummyFileSystemService',
                    'internal_bucket_id' => '018e1902-c4b6-7e9a-9488-084daf6b3218',
                    'bucket_id' => 'test-bucket-2',
                    'key' => 'f5b08061e9989d0357c4173aa3af9bc05d0400121af5f90a43e6cdb91ff1fbf2',
                    'quota' => 500, // in MB
                    'notify_when_quota_over' => 70, // in percent of quota
                    'report_when_expiry_in' => 'P62D', // in Days, 62 = two 31 day months
                    'bucket_owner' => 'manuel.kocher@tugraz.at',
                    'max_retention_duration' => 'P1Y',
                    'link_expire_time' => 'PT1M',
                    'notify_quota' => [
                        'dsn' => 'smtp:localhost',
                        'from' => 'noreply@tugraz.at',
                        'to' => 'tamara.steinwender@tugraz.at',
                        'subject' => 'Blob notify quota',
                        'html_template' => 'emails/notify-quota.html.twig',
                    ],
                    'reporting' => [
                        'dsn' => 'smtp:localhost',
                        'from' => 'noreply@tugraz.at',
                        'to' => 'tamara.steinwender@tugraz.at',
                        'subject' => 'Blob file deletion reporting',
                        'html_template' => 'emails/reporting.html.twig',
                    ],
                ],
            ],
        ]);
    }
}
