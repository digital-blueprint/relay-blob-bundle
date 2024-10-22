<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Cron;

use Dbp\Relay\BlobBundle\Configuration\ConfigurationService;
use Dbp\Relay\BlobBundle\Service\BlobService;
use Dbp\Relay\CoreBundle\Cron\CronJobInterface;
use Dbp\Relay\CoreBundle\Cron\CronOptions;

class SendReportCronJob implements CronJobInterface
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
        return 'Blob File send reports';
    }

    public function getInterval(): string
    {
        return $this->configService->getReportingInterval(); // At 14:00 on Monday.
    }

    public function run(CronOptions $options): void
    {
        assert($this->blobService !== null);
        // $this->blobService->sendReporting();
    }
}
