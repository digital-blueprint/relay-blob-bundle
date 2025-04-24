<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Cron;

use Dbp\Relay\BlobBundle\Configuration\ConfigurationService;
use Dbp\Relay\BlobBundle\Service\BlobChecks;
use Dbp\Relay\CoreBundle\Cron\CronJobInterface;
use Dbp\Relay\CoreBundle\Cron\CronOptions;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

readonly class FileAndMetadataIntegrityCheckCronJob implements CronJobInterface
{
    public function __construct(
        private BlobChecks $blobChecks,
        private ConfigurationService $configService)
    {
    }

    public function getName(): string
    {
        return 'Blob File check integrity';
    }

    public function getInterval(): string
    {
        return $this->configService->getIntegrityCheckInterval();
    }

    /**
     * @throws \Exception
     * @throws TransportExceptionInterface
     */
    public function run(CronOptions $options): void
    {
        if ($this->configService->runFileIntegrityHealthchecks()) {
            $this->blobChecks->checkFileAndMetadataIntegrity();
        }
    }
}
