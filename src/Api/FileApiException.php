<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Api;

class FileApiException extends \Exception
{
    public const FILE_NOT_FOUND = 1;

    public function __construct(string $message, int $code, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
