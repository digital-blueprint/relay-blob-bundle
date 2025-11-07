<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Task;

use Dbp\Relay\BlobBundle\Entity\MetadataBackupJob;

class MetadataBackupTask
{
    public function __construct(private MetadataBackupJob $job)
    {
    }

    public function getJob(): MetadataBackupJob
    {
        return $this->job;
    }
}