<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Authorization;

class BucketData
{
    public function __construct(private readonly string $name)
    {
    }

    public function getName(): string
    {
        return $this->name;
    }
}
