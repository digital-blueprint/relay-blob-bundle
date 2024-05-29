<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Event;

use Dbp\Relay\BlobBundle\Entity\FileData;
use Symfony\Contracts\EventDispatcher\Event;

class AddFileDataByPostSuccessEvent extends Event
{
    protected $filedata;

    public function __construct(FileData $filedata)
    {
        $this->filedata = $filedata;
    }

    public function getFileData(): FileData
    {
        return $this->filedata;
    }
}