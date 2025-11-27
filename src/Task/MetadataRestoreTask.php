<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Task;

use Dbp\Relay\BlobBundle\Entity\MetadataRestoreJob;

class MetadataRestoreTask
{
    public function __construct(private MetadataRestoreJob $job)
    {
    }

    public function getJob(): MetadataRestoreJob
    {
        return $this->job;
    }
}
