<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Helper;

use Dbp\Relay\CoreBundle\Exception\ApiError;
use Kekos\MultipartFormDataParser\Parser;
use Kekos\MultipartFormDataParser\ParserException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * @internal
 */
class PatchRequestParser
{
    /**
     * Uploaded files are only available during the callback.
     *
     * @template T
     *
     * @param callable(Request): T $process
     *
     * @return T
     */
    public function withParsedRequest(Request $request, callable $process): mixed
    {
        $uploadFactory = $this->createUploadFactory();
        try {
            $psrRequest = $this->parse($request);

            return $process($uploadFactory->createRequest($psrRequest));
        } finally {
            $uploadFactory->cleanup();
        }
    }

    private function parse(Request $request): ServerRequestInterface
    {
        BlobUtils::checkRequestSize($request);

        $contentType = trim(explode(';', $request->headers->get('Content-Type', ''))[0]);
        if (strcasecmp($contentType, 'multipart/form-data') !== 0) {
            throw new ApiError(Response::HTTP_UNSUPPORTED_MEDIA_TYPE, 'File PATCH requests require multipart/form-data');
        }

        $psrFactory = new Psr17Factory();
        $psrHttpFactory = new PsrHttpFactory($psrFactory, $psrFactory, $psrFactory, $psrFactory);
        $psrRequest = $psrHttpFactory->createRequest($request);

        // Normalize the media type for the case-sensitive parser without changing the boundary.
        $contentTypeParts = explode(';', $psrRequest->getHeaderLine('Content-Type'), 2);
        $contentTypeParts[0] = Parser::CONTENT_TYPE_MULTIPART;
        $psrRequest = $psrRequest->withHeader('Content-Type', implode(';', $contentTypeParts));
        try {
            $parser = Parser::createFromRequest($psrRequest, $psrFactory, $psrFactory);
        } catch (ParserException $exception) {
            throw new ApiError(Response::HTTP_BAD_REQUEST, 'Malformed multipart/form-data request', $exception);
        }

        return $parser->decorateRequest($psrRequest);
    }

    protected function createUploadFactory(): TemporaryUploadFactory
    {
        return new TemporaryUploadFactory();
    }
}
