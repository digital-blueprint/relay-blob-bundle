<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Helper;

use Dbp\Relay\CoreBundle\Exception\ApiError;
use Kekos\MultipartFormDataParser\Parser;
use Kekos\MultipartFormDataParser\ParserException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class BlobUtils
{
    public static function convertPatchRequest(Request $request): Request
    {
        $contentType = trim(explode(';', $request->headers->get('Content-Type', ''))[0]);
        if (strcasecmp($contentType, 'multipart/form-data') !== 0) {
            throw new ApiError(Response::HTTP_UNSUPPORTED_MEDIA_TYPE, 'File PATCH requests require multipart/form-data');
        }

        $uploaded_file_factory = new Psr17Factory();
        $stream_factory = $uploaded_file_factory;

        $bridgeFactory = new Psr17Factory();
        $psrHttpFactory = new PsrHttpFactory($bridgeFactory, $bridgeFactory, $bridgeFactory, $bridgeFactory);

        // convert to psr7 to decorate request with multipart formdata
        $psrRequest = $psrHttpFactory->createRequest($request);
        // Normalize the media type for the case-sensitive parser without changing the boundary.
        $contentTypeParts = explode(';', $psrRequest->getHeaderLine('Content-Type'), 2);
        $contentTypeParts[0] = Parser::CONTENT_TYPE_MULTIPART;
        $psrRequest = $psrRequest->withHeader('Content-Type', implode(';', $contentTypeParts));
        try {
            $parser = Parser::createFromRequest($psrRequest, $uploaded_file_factory, $stream_factory);
        } catch (ParserException $exception) {
            throw new ApiError(Response::HTTP_BAD_REQUEST, 'Malformed multipart/form-data request', $exception);
        }
        $psrRequest = $parser->decorateRequest($psrRequest);

        // convert back to symfony request to get a symfony uploadedFile instead of a PSR-7 uploadedFile
        $httpFoundationFactory = new HttpFoundationFactory();

        return $httpFoundationFactory->createRequest($psrRequest);
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
