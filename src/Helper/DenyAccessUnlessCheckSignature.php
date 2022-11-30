<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Helper;

class DenyAccessUnlessCheckSignature
{
    public static function denyAccessUnlessSiganture(string $requestBucketID, string $requestCreationTime, string $uri, string $signature, $payload = null): void
    {
        dump("---------------");
        dump($requestBucketID . " " . $requestCreationTime . " + " . $signature . "\n"
            . "uri: " . $uri . "\n"
            . "payload: " . $payload);

        // throw $exception;
    }
}
