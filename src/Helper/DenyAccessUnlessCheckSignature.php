<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Helper;

use Dbp\Relay\BlobBundle\Service\BlobService;
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
     * @param string  $sig     to verify
     * @param Request $request incoming request
     *
     * @return array extracted payload from token
     *
     * @throws ApiError
     */
    private static function verifyChecksumAndSignature(string $secret, string $sig, Request $request): array
    {
        $data = self::verify($secret, $sig);

        // check checksum of only url since no body is expected
        if (!array_key_exists('ucs', $data) || $data['ucs'] !== self::generateSha256FromRequest($request)) {
            throw ApiError::withDetails(Response::HTTP_FORBIDDEN, 'Checksum ucs invalid', 'blob:checksum-invalid');
        }

        return $data;
    }

    /**
     * Checks if the parameters bucketID, creationTime, method and sig are present and valid,
     * either using filter or the request itself.
     * Also checks if the creationTime is too old, the bucket with given ID is configured, and if the specified method is allowed.
     *
     * @throws \Exception
     */
    public static function checkSignature(string $errorPrefix, BlobService $blobService, Request $request,
        array $filters = [], array $allowedMethods = []): void
    {
        $sig = $filters['sig'] ?? '';
        $bucketID = $filters['bucketIdentifier'] ?? '';
        $creationTime = $filters['creationTime'] ?? '';
        $urlMethod = $filters['method'] ?? '';

        // check type of params
        assert(is_string($sig));
        assert(is_string($bucketID));
        assert(is_string($creationTime));
        assert(is_string($urlMethod));

        // decode params according to RFC3986
        $sig = rawurldecode($sig);
        $bucketID = rawurldecode($bucketID);
        $creationTime = rawurldecode($creationTime);
        $urlMethod = rawurldecode($urlMethod);

        // check if signature is present
        if (!$sig) {
            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'signature missing', $errorPrefix.'-missing-sig');
        }
        // check if bucketID is present
        if (!$bucketID) {
            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'bucketID is missing', $errorPrefix.'-missing-bucket-id');
        }
        // check if creationTime is present
        if (!$creationTime) {
            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'creationTime is missing', $errorPrefix.'-missing-creation-time');
        }
        // check if method in url is present
        if (!$urlMethod) {
            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'method is missing', $errorPrefix.'-missing-method');
        }
        // check if bucket with given bucketID is configured
        $bucket = $blobService->getConfigurationService()->getBucketByID($bucketID);
        if (!$bucket) {
            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'bucketID is not configured', $errorPrefix.'-bucket-id-not-configured');
        }

        // get link expiry date and current date
        $linkExpiryTime = $bucket->getLinkExpireTime();

        // sub linkexpirytime from now to check if creationTime is too old
        $now = new \DateTime('now');
        $expiryTime = $now->sub(new \DateInterval($linkExpiryTime));

        // add linkExpiryTime to now, and allow requests to be only linkExpiryTime in the future
        $now = new \DateTime('now');
        $futureBlock = $now->add(new \DateInterval($linkExpiryTime));

        $creationDateTime = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $creationTime);
        if ($creationDateTime === false) {
            // RFC3339_EXTENDED is broken in PHP
            $creationDateTime = \DateTimeImmutable::createFromFormat("Y-m-d\TH:i:s.uP", $creationTime);
        }

        // check if creationTime is in the correct format
        if ($creationDateTime === false) {
            throw ApiError::withDetails(Response::HTTP_FORBIDDEN, 'Parameter creationTime is in a bad format!', 'blob:check-signature-creation-time-bad-format');
        }

        // check if request is expired or in the future
        if ($creationDateTime < $expiryTime || $creationDateTime > $futureBlock) {
            throw ApiError::withDetails(Response::HTTP_FORBIDDEN, 'Parameter creationTime too old', $errorPrefix.'-creation-time-too-old');
        }

        // check if bucket service is configured
        if (!$bucket->getService()) {
            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'bucketService is not configured', $errorPrefix.'-no-bucket-service');
        }

        // check if method specified in url and actual method used match
        $method = $request->getMethod();
        if ($urlMethod !== $method || !in_array($method, $allowedMethods, true)) {
            throw ApiError::withDetails(Response::HTTP_METHOD_NOT_ALLOWED, 'method is not suitable', $errorPrefix.'-method-not-suitable');
        }

        // verify signature and checksum
        DenyAccessUnlessCheckSignature::verifyChecksumAndSignature($bucket->getKey(), $sig, $request);
    }

    /**
     * Generates a sha256 hash from the request url except with the trailing &sig part cut out.
     */
    public static function generateSha256FromRequest(Request $request): string
    {
        // remove signature part of uri
        $url = explode('&sig=', $request->getRequestUri());

        assert(is_string($url[0]));

        // generate hmac sha256 hash over the uri except the signature part
        return SignatureTools::generateSha256Checksum($url[0]);
    }
}
