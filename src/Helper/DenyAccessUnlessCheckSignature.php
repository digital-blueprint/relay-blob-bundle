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
    public static function verifyChecksumAndSignature(string $secret, string $sig, Request $request): array
    {
        $data = self::verify($secret, $sig);

        $method = $request->getMethod();
        if ($method === 'POST' || $method === 'PATCH') {
            // check checksum of url and body since both are expected
            if (!array_key_exists('ucs', $data) || $data['ucs'] !== self::generateSha256FromRequest($request)
                || !array_key_exists('bcs', $data) || $data['bcs'] !== self::generateSha256FromRequestBody($request)) {
                throw ApiError::withDetails(Response::HTTP_FORBIDDEN, 'Checksum bcs or ucs invalid', 'blob:checksum-invalid');
            }
        } else {
            // check checksum of only url since no body is expected
            if (!array_key_exists('ucs', $data) || $data['ucs'] !== self::generateSha256FromRequest($request)) {
                throw ApiError::withDetails(Response::HTTP_FORBIDDEN, 'Checksum ucs invalid', 'blob:checksum-invalid');
            }
        }

        return $data;
    }

    /**
     * Check presence of minimal parameter set, check creationTime, bucketID and method and verify signature and cs.
     *
     * @throws \JsonException
     */
    public static function checkSignature(string $secret, Request $request, BlobService $blobService, bool $externallyAuthenicated = true): void
    {
        // check if externally authenticated
        if (!$externallyAuthenicated) {
            throw ApiError::withDetails(Response::HTTP_FORBIDDEN, 'Not authenticated!', 'blob:not-authenticated');
        }

        // check if signature is present
        /** @var string */
        $sig = $request->query->get('sig', '');
        if (!$sig) {
            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'Signature missing', 'blob:check-signature-missing-sig');
        }

        /** @var string $bucketID $creationTime $urlMethod */
        $bucketID = $request->query->get('bucketID', '');
        /** @var string $creationTime */
        $creationTime = $request->query->get('creationTime', '0');
        /** @var string $urlMethod */
        $urlMethod = $request->query->get('method', '');

        $bucketID = rawurldecode($bucketID);
        $creationTime = rawurldecode($creationTime);
        $urlMethod = rawurldecode($urlMethod);
        $sig = rawurldecode($sig);

        // check if the minimal params are present
        if (!$bucketID || !$creationTime || !$urlMethod) {
            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'bucketID, creationTime or method parameter missing', 'blob:check-signature-missing-signature-params');
        }

        // verify signature and checksum
        DenyAccessUnlessCheckSignature::verifyChecksumAndSignature($secret, $sig, $request);

        // now, after the signature and checksum check it is safe to something

        $bucket = $blobService->configurationService->getBucketByID($bucketID);

        // get the time for which a request should be valid
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

        // check if request is expired
        if ($creationDateTime < $expiryTime || $creationDateTime > $futureBlock) {
            throw ApiError::withDetails(Response::HTTP_FORBIDDEN, 'Parameter creationTime too old', 'blob:check-signature-creation-time-too-old');
        }

        // check action/method
        $method = $request->getMethod();

        // check if the provided method and action is suitable
        if (($method === 'GET' && $urlMethod !== 'GET')
            || ($method === 'DELETE' && $urlMethod !== 'DELETE')
            || ($method === 'PATCH' && $urlMethod !== 'PATCH')
            || ($method === 'POST' && $urlMethod !== 'POST')
        ) {
            throw ApiError::withDetails(Response::HTTP_METHOD_NOT_ALLOWED, 'Method and/or action not suitable', 'blob:check-signature-method-not-suitable');
        }
    }

    /**
     * Checks if the parameters bucketID, creationTime, method and sig are present and valid,
     * either using filter or the request itself.
     * Also checks if the creationTime is too old, the bucket with given ID is configured, and if the specified method is allowed.
     *
     * @return void
     *
     * @throws \Exception
     */
    public static function checkMinimalParameters(string $errorPrefix, BlobService $blobService, Request $request, array $filters = [], array $allowedMethods = [])
    {
        // either use filters or request to get parameters, depending on which is provided
        if ($filters) {
            $sig = $filters['sig'] ?? '';
            $bucketID = $filters['bucketID'] ?? '';
            $creationTime = $filters['creationTime'] ?? '';
            $urlMethod = $filters['method'] ?? '';
        } else {
            // check if signature is present
            $sig = $request->query->get('sig', '');
            $bucketID = $request->query->get('bucketID', '');
            $creationTime = $request->query->get('creationTime', '');
            $urlMethod = $request->query->get('method', '');
        }

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

        // check if bucketID is present
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
        $bucket = $blobService->configurationService->getBucketByID($bucketID);
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

    /**
     * Generates a sha256 hash from the request body.
     */
    public static function generateSha256FromRequestBody(Request $request): string
    {
        $body = '';

        // extract data using different methods
        // for POST
        if ($request->getMethod() === 'POST') {
            $body = json_encode($request->request->all(), JSON_FORCE_OBJECT);
        }
        // for PATCH
        elseif ($request->getMethod() === 'PATCH') {
            $body = $request->getContent();
        }

        assert(is_string($body));

        // generate hmac sha256 hash over the body
        return SignatureTools::generateSha256Checksum($body);
    }
}
