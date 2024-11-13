<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Cron;

use Dbp\Relay\BlobBundle\Configuration\ConfigurationService;
use Dbp\Relay\BlobBundle\Service\BlobService;
use Dbp\Relay\CoreBundle\Cron\CronJobInterface;
use Dbp\Relay\CoreBundle\Cron\CronOptions;

class CleanupCronJob implements CronJobInterface
{
    /**
     * @var BlobService
     */
    private $blobService;

    private $configService;

    public function __construct(BlobService $blobService, ConfigurationService $configService)
    {
        $this->blobService = $blobService;
        $this->configService = $configService;
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
