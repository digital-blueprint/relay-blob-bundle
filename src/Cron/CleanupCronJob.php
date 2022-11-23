<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Cron;

use Dbp\Relay\BlobBundle\Service\BlobService;
use Dbp\Relay\CoreBundle\Cron\CronJobInterface;
use Dbp\Relay\CoreBundle\Cron\CronOptions;

class CleanupCronJob implements CronJobInterface
{
    /**
     * @var BlobService
     */
    private $blobService;

    public function __construct(BlobService $blobService)
    {
        $this->blobService = $blobService;
    }

    public function getName(): string
    {
        return 'Blob File cleanup';
    }

    public function getInterval(): string
    {
        return '0 * * * *'; // Every hour
    }

    public function run(CronOptions $options): void
    {
        $this->blobService->cleanUp();
    }
}
