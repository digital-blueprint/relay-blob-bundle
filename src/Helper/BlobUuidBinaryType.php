<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Helper;

use Ramsey\Uuid\Doctrine\UuidBinaryType;

class BlobUuidBinaryType extends UuidBinaryType
{
    public const NAME = 'relay_blob_uuid_binary';
}
