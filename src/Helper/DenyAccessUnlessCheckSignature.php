<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Helper;

use Dbp\Relay\BlobLibrary\Helpers\SignatureTools;
use Dbp\Relay\CoreBundle\Exception\ApiError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class DenyAccessUnlessCheckSignature
{
    /**
     * Create a JWS token.
     *
     * @param string $secret  to create the (symmetric) JWK from
     * @param array  $payload to create the token from
     *
     * @throws \JsonException
     */
    public static function create(string $secret, array $payload): string
    {
        return SignatureTools::create($secret, $payload);
    }

    /**
     * Verify a JWS token.
     *
     * @param string $secret to create the (symmetric) JWK from
     * @param string $token  to verify
     *
     * @return array extracted payload from token
     *
     * @throws ApiError
     */
    public static function verify(string $secret, string $token): array
    {
        try {
            $payload = SignatureTools::verify($secret, $token);
        } catch (\Exception $e) {
            throw ApiError::withDetails(Response::HTTP_FORBIDDEN, 'Signature invalid', 'blob:signature-invalid');
        }

        return $payload;
    }

    /**
     * Verify a JWS token and the checksum inside the signature.
     *
     * @param string  $secret  to create the (symmetric) JWK from
     * @param string  $token   to verify
     * @param Request $request incoming request
     *
     * @return array extracted payload from token
     *
     * @throws \JsonException
     * @throws ApiError
     */
    public static function verifyChecksumAndSignature(string $secret, string $token, Request $request): array
    {
        $data = self::verify($secret, $token);

        // check checksum
        if (!array_key_exists('cs', $data) || $data['cs'] !== self::generateSha256($request)) {
            throw ApiError::withDetails(Response::HTTP_FORBIDDEN, 'Checksum invalid', 'blob:signature-invalid');
        }

        return $data;
    }

    public static function checkNeededParamsAndMethod(Request $request, string $method)
    {
        $bucketId = $request->query->get('bucketID', '');
        $creationTime = $request->query->get('creationTime', 0);
        $action = $request->query->get('action', '');
        $sig = $request->query->get('sig', '');
        // check checksum
        if (!$bucketId || !$creationTime || !$action || !$sig || $request->getMethod() !== $method) {
            throw ApiError::withDetails(Response::HTTP_FORBIDDEN, 'BucketID, creationTime or action missing!', 'blob:signature-invalid');
        }
    }

    public static function generateSha256(Request $request): string
    {
        // remove signature part of uri
        $url = explode('&sig=', $request->getRequestUri());

        // generate hmac sha256 hash over the uri except the signature part
        return SignatureTools::generateSha256Checksum($url[0]);
    }
}
