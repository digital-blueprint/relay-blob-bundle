<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Helper;

use Dbp\Relay\CoreBundle\Exception\ApiError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class BlobUtils
{
    public static function checkRequestSize(Request $request): void
    {
        $maxSize = ini_parse_quantity(ini_get('post_max_size'));
        $contentLength = $request->headers->get('Content-Length');

        // A zero post_max_size means unlimited uploads.
        if ($maxSize > 0 && $contentLength !== null && intval($contentLength) > $maxSize) {
            throw new ApiError(Response::HTTP_BAD_REQUEST, 'The request exceeds the configured post_max_size');
        }
    }

    public static function parseRequestDuration(string $duration, string $parameterName): \DateInterval
    {
        try {
            return new \DateInterval($duration);
        } catch (\Exception) {
            throw new ApiError(Response::HTTP_BAD_REQUEST, 'Parameter '.$parameterName.' must be a valid ISO 8601 duration');
        }
    }

    public static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public static function formatBytes(int $bytes): string
    {
        if ($bytes >= 1024 * 1024 * 1024) {
            return round($bytes / (1024 * 1024 * 1024), 1).' GB';
        } elseif ($bytes >= 1024 * 1024) {
            return round($bytes / (1024 * 1024), 1).' MB';
        } elseif ($bytes >= 1024) {
            return round($bytes / 1024, 1).' KB';
        }

        return $bytes.' B';
    }
}
