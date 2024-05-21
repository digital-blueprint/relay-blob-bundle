<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Cron;

use Dbp\Relay\BlobBundle\Service\BlobService;
use Dbp\Relay\BlobBundle\Service\ConfigurationService;
use Dbp\Relay\CoreBundle\Cron\CronJobInterface;
use Dbp\Relay\CoreBundle\Cron\CronOptions;

class SendQuotaWarningCronJob implements CronJobInterface
{
    /**
     * @var BlobService
     */
    private $blobService;

    /**
     * @var ConfigurationService
     */
    private $configService;

    public function __construct(BlobService $blobService, ConfigurationService $configService)
    {
        $this->blobService = $blobService;
        $this->configService = $configService;
    }

    public function getName(): string
    {
        return 'Blob File send quota warnings';
    }

    public function getInterval(): string
    {
        return $this->configService->getQuotaWarningInterval(); // At 14:00 on Monday.
    }

    public function run(CronOptions $options): void
    {
        $this->blobService->sendWarning();
    }
}
