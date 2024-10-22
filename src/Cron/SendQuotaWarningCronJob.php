<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Cron;

use Dbp\Relay\BlobBundle\Configuration\ConfigurationService;
use Dbp\Relay\BlobBundle\Service\BlobChecks;
use Dbp\Relay\CoreBundle\Cron\CronJobInterface;
use Dbp\Relay\CoreBundle\Cron\CronOptions;

class SendQuotaWarningCronJob implements CronJobInterface
{
    /**
     * @var BlobChecks
     */
    private $blobChecks;

    /**
     * @var ConfigurationService
     */
    private $configService;

    public function __construct(BlobChecks $blobChecks, ConfigurationService $configService)
    {
        $this->blobChecks = $blobChecks;
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
        $this->blobChecks->checkQuotas();
    }
}
