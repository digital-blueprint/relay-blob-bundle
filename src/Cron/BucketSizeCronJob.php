<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Cron;

use Dbp\Relay\BlobBundle\Configuration\ConfigurationService;
use Dbp\Relay\BlobBundle\Service\BlobChecks;
use Dbp\Relay\CoreBundle\Cron\CronJobInterface;
use Dbp\Relay\CoreBundle\Cron\CronOptions;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;

readonly class BucketSizeCronJob implements CronJobInterface
{
    public function __construct(
        private BlobChecks $blobChecks,
        private ConfigurationService $configService)
    {
    }

    public function getName(): string
    {
        return 'Blob bucket size update';
    }

    public function getInterval(): string
    {
        return $this->configService->getBucketSizeCheckInterval();
    }

    /**
     * @throws SyntaxError
     * @throws TransportExceptionInterface
     * @throws RuntimeError
     * @throws LoaderError
     */
    public function run(CronOptions $options): void
    {
        $this->blobChecks->checkStorage();
    }
}
