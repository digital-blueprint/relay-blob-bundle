<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Cron;

use Dbp\Relay\BlobBundle\Configuration\ConfigurationService;
use Dbp\Relay\BlobBundle\Service\BlobChecks;
use Dbp\Relay\CoreBundle\Cron\CronJobInterface;
use Dbp\Relay\CoreBundle\Cron\CronOptions;

readonly class SendQuotaWarningCronJob implements CronJobInterface
{
    public function __construct(
        private BlobChecks $blobChecks,
        private ConfigurationService $configService)
    {
    }

    public function getName(): string
    {
        return 'Blob File send quota warnings';
    }

    public function getInterval(): string
    {
        return $this->configService->getQuotaWarningInterval(); // At 14:00 on Monday.
    }

    /**
     * @throws \Exception
     */
    public function run(CronOptions $options): void
    {
        $this->blobChecks->checkQuotas();
    }
}
