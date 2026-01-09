<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Helper;

use Kekos\MultipartFormDataParser\Parser;
use Nyholm\Psr7\Factory\Psr17Factory;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Component\HttpFoundation\Request;

class BlobUtils
{
    public static function convertPatchRequest(Request $request): Request
    {
        $uploaded_file_factory = new Psr17Factory();
        $stream_factory = $uploaded_file_factory;

        $bridgeFactory = new Psr17Factory();
        $psrHttpFactory = new PsrHttpFactory($bridgeFactory, $bridgeFactory, $bridgeFactory, $bridgeFactory);

        // convert to psr7 to decorate request with multipart formdata
        $psrRequest = $psrHttpFactory->createRequest($request);
        $parser = Parser::createFromRequest($psrRequest, $uploaded_file_factory, $stream_factory);
        $psrRequest = $parser->decorateRequest($psrRequest);

        // convert back to symfony request to get a symfony uploadedFile instead of a PSR-7 uploadedFile
        $httpFoundationFactory = new HttpFoundationFactory();

        return $httpFoundationFactory->createRequest($psrRequest);
    }

    public static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now');
    }
}
