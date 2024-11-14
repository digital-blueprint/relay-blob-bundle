<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Event;

use Dbp\Relay\BlobBundle\Entity\FileData;
use Symfony\Contracts\EventDispatcher\Event;

abstract class SuccessEvent extends Event
{
    public function __construct(protected readonly FileData $fileData)
    {
    }

    public function getFileData(): FileData
    {
        return $this->fileData;
    }
}
