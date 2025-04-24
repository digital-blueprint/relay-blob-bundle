<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Cron;

use Dbp\Relay\BlobBundle\Configuration\ConfigurationService;
use Dbp\Relay\BlobBundle\Service\BlobService;
use Dbp\Relay\CoreBundle\Cron\CronJobInterface;
use Dbp\Relay\CoreBundle\Cron\CronOptions;

class CleanupCronJob implements CronJobInterface
{
    public function __construct(
        private readonly BlobService $blobService,
        private readonly ConfigurationService $configService)
    {
    }

    public function getName(): string
    {
        return 'Blob File cleanup';
    }

    public function getInterval(): string
    {
        return $this->configService->getCleanupInterval();
    }

    /**
     * @throws \Exception
     */
    public function run(CronOptions $options): void
    {
        $this->blobService->cleanUp();
    }
}
