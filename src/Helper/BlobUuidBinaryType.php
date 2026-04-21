<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Helper;

use Doctrine\DBAL\ParameterType;
use Symfony\Bridge\Doctrine\Types\AbstractUidType;
use Symfony\Component\Uid\Uuid;

class BlobUuidBinaryType extends AbstractUidType
{
    public const NAME = 'relay_blob_uuid_binary';

    public function getName(): string
    {
        return self::NAME;
    }

    protected function getUidClass(): string
    {
        return Uuid::class;
    }

    public function getBindingType(): ParameterType
    {
        return ParameterType::BINARY;
    }
}
