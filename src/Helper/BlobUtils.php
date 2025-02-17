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

    public static function convertFileSizeStringToBytes($sizeStr): int
    {
        $fileSizeExt = ['k', 'm', 'g'];

        // check if one of the shorthand byte options is used
        // only K, M and G are available according to PHP docs
        if (!is_numeric(substr($sizeStr, -1)) && in_array(strtolower(substr($sizeStr, -1)), $fileSizeExt, false)) {
            $sizeWithoutPostfixStr = substr($sizeStr, 0, strlen($sizeStr) - 1);

            $multiplicator = array_search(strtolower(substr($sizeStr, -1)), $fileSizeExt, false);

            assert(is_int($multiplicator));

            $multiplicator = $multiplicator + 1;

            $sizeWithoutPostfix = intval($sizeWithoutPostfixStr);

            // === 0 means error
            assert($sizeWithoutPostfix !== 0);

            $sizeInBytes = $sizeWithoutPostfix * pow(1024, $multiplicator);

            return $sizeInBytes;
        } else {
            // at this point the string should be numeric
            assert(is_numeric($sizeStr));

            $size = intval($sizeStr);

            // === 0 means error
            assert($size !== 0);

            return $size;
        }
    }

    public static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now');
    }
}
