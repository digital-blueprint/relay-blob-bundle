<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Tests;

use ApiPlatform\Core\Bridge\Symfony\Bundle\ApiPlatformBundle;
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
//            'database_url' => 'sqlite:///:memory:',
            'buckets' => [
                'test_bucket' => [
                    'service' => 'Dbp\Relay\BlobBundle\Tests\DummyFileSystemService',
                    'bucket_id' => '1234',
                    'bucket_name' => 'Test bucket',
                    'public_key' => '08d848fd868d83646778b87dd0695b10f59c78e23b286e9884504d1bb43cce93',
                    'path' => 'testpath',
                    'quota' => 500, // in MB
                    'notify_when_quota_over' => 400, // in MB
                    'report_when_expiry_in' => 'P62D', // in Days, 62 = two 31 day months
                    'bucket_owner' => 'manuel.kocher@tugraz.at',
                    'max_retention_duration' => 'P1Y',
                    'link_expire_time' => 'P7D',
                    'policies' => [
                        'create' => true,
                        'delete' => true,
                        'open' => true,
                        'download' => true,
                        'rename' => true,
                        'work' => true,
                    ],
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
