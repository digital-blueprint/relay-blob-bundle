<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Helper;

use Dbp\Relay\BlobBundle\Configuration\ConfigurationService;
use Dbp\Relay\BlobLibrary\Helpers\SignatureTools;
use Dbp\Relay\CoreBundle\Exception\ApiError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class SignatureUtils
{
    /**
     * Checks if the query parameters bucketIdentifier, creationTime, method and sig are present and valid.
     * Also checks if the creationTime is too old, the bucket with given ID is configured, and if the specified method is allowed.
     *
     * @throws \Exception
     */
    public static function checkSignature(ConfigurationService $config, Request $request,
        array $allowedMethods = []): void
    {
        $filters = $request->query->all();

        foreach (['sig', 'bucketIdentifier'] as $parameterName) {
            $value = $filters[$parameterName] ?? null;
            if ($value !== null && !is_string($value)) {
                throw new ApiError(Response::HTTP_BAD_REQUEST, 'Parameter '.$parameterName.' must be a string');
            }
        }

        $signature = $filters['sig'] ?? null;
        $bucketIdentifier = $filters['bucketIdentifier'] ?? null;

        if (!$signature) {
            throw new ApiError(Response::HTTP_BAD_REQUEST, 'Parameter sig is missing');
        }
        if (!$bucketIdentifier) {
            throw new ApiError(Response::HTTP_BAD_REQUEST, 'Parameter bucketIdentifier is missing');
        }
        $bucket = $config->getBucketById($bucketIdentifier);
        if ($bucket === null) {
            throw new ApiError(Response::HTTP_BAD_REQUEST, 'The specified bucketIdentifier is not configured');
        }

        // Exclude the deployment prefix while preserving the signed query's original encoding and order.
        $requestUri = $request->getPathInfo().(strstr($request->getRequestUri(), '?') ?: '');
        $bucketKey = $bucket->getKey();
        if ($bucketKey === null || false === SignatureUtils::isSignatureValid($bucketKey, $signature, $requestUri)) {
            throw ApiError::withDetails(Response::HTTP_FORBIDDEN, 'Signature invalid', 'blob:signature-invalid');
        }

        foreach (['creationTime', 'method', 'expireIn'] as $parameterName) {
            $value = $filters[$parameterName] ?? null;
            if ($value !== null && !is_string($value)) {
                throw new ApiError(Response::HTTP_BAD_REQUEST, 'Parameter '.$parameterName.' must be a string');
            }
        }

        $creationTime = $filters['creationTime'] ?? null;
        $urlMethod = $filters['method'] ?? null;
        $expiryDuration = $filters['expireIn'] ?? null;

        if (!$creationTime) {
            throw new ApiError(Response::HTTP_BAD_REQUEST, 'Parameter creationTime is missing');
        }
        if (!$urlMethod) {
            throw new ApiError(Response::HTTP_BAD_REQUEST, 'Parameter method is missing');
        }

        $now = BlobUtils::now();
        $allowedLinkLifetime = new \DateInterval($bucket->getLinkExpireTime());

        if ($expiryDuration) {
            $requestedLinkLifetime = BlobUtils::parseRequestDuration($expiryDuration, 'expireIn');
            if ($now->add($requestedLinkLifetime) > $now->add($allowedLinkLifetime)) {
                throw new ApiError(Response::HTTP_BAD_REQUEST, 'Parameter expireIn exceeds the configured link lifetime');
            }
            $allowedLinkLifetime = $requestedLinkLifetime;
        }

        try {
            $creationDateTime = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $creationTime);
            if ($creationDateTime === false) {
                // RFC3339_EXTENDED is broken in PHP
                $creationDateTime = \DateTimeImmutable::createFromFormat("Y-m-d\TH:i:s.uP", $creationTime);
                if ($creationDateTime === false) {
                    throw new ApiError(Response::HTTP_BAD_REQUEST, 'Parameter creationTime must be a valid ISO 8601 timestamp');
                }
            }
        } catch (\ValueError) {
            throw new ApiError(Response::HTTP_BAD_REQUEST, 'Parameter creationTime must be a valid ISO 8601 timestamp');
        }

        $earliestAllowedCreationTime = $now->sub($allowedLinkLifetime);
        $latestAllowedCreationTime = $now->add($allowedLinkLifetime);

        // check if the request is expired or in the future
        if ($creationDateTime < $earliestAllowedCreationTime || $creationDateTime > $latestAllowedCreationTime) {
            $message = $creationDateTime < $earliestAllowedCreationTime
                ? 'Signed URL has expired: parameter creationTime is outside the allowed lifetime'
                : 'Signed URL is not valid yet: parameter creationTime is too far in the future';
            throw ApiError::withDetails(Response::HTTP_FORBIDDEN,
                $message, 'blob:creation-time-out-of-range');
        }

        // Treat HEAD with a GET signature as GET for both method matching and the allowlist.
        $method = $request->getMethod();
        if ($method === 'HEAD' && $urlMethod === 'GET') {
            $method = 'GET';
        }
        if ($urlMethod !== $method || !in_array($method, $allowedMethods, true)) {
            throw new ApiError(Response::HTTP_BAD_REQUEST, 'Parameter method must match the request method and be allowed on this endpoint');
        }
    }

    /**
     * Verify a JWS token and the checksum inside the signature.
     *
     * @param string $bucketKey  to create the (symmetric) JWK from
     * @param string $signature  to verify
     * @param string $requestUri incoming request URI
     */
    private static function isSignatureValid(string $bucketKey, string $signature, string $requestUri): bool
    {
        // Require the signature to be the final parameter so all request parameters are signed.
        $url = explode('&sig=', $requestUri, 2);
        if (count($url) !== 2 || $url[1] !== $signature) {
            return false;
        }

        try {
            $payload = SignatureTools::verifySignature($bucketKey, $signature);
            if (array_key_exists('ucs', $payload) && $payload['ucs'] === hash('sha256', $url[0])) {
                return true;
            }
        } catch (\Exception) {
        }

        return false;
    }
}
