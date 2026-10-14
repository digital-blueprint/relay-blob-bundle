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
     * Checks if the parameters bucketID, creationTime, method and sig are present and valid,
     * either using filter or the request itself.
     * Also checks if the creationTime is too old, the bucket with given ID is configured, and if the specified method is allowed.
     *
     * @throws \Exception
     */
    public static function checkSignature(string $errorPrefix, ConfigurationService $config, Request $request,
        array $filters = [], array $allowedMethods = []): void
    {
        foreach (['sig', 'bucketIdentifier', 'creationTime', 'method', 'expireIn'] as $parameterName) {
            $value = $filters[$parameterName] ?? null;
            if ($value !== null && !is_string($value)) {
                throw new ApiError(Response::HTTP_BAD_REQUEST, 'Parameter '.$parameterName.' is invalid');
            }
        }

        $signature = $filters['sig'] ?? null;
        $bucketIdentifier = $filters['bucketIdentifier'] ?? null;
        $creationTime = $filters['creationTime'] ?? null;
        $urlMethod = $filters['method'] ?? null;
        $expiryDuration = $filters['expireIn'] ?? null;

        if (!$signature) {
            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST,
                'signature missing', $errorPrefix.'-missing-sig');
        }
        if (!$bucketIdentifier) {
            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST,
                'bucketID is missing', $errorPrefix.'-missing-bucket-id');
        }
        if (!$creationTime) {
            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST,
                'creationTime is missing', $errorPrefix.'-missing-creation-time');
        }
        if (!$urlMethod) {
            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST,
                'method is missing', $errorPrefix.'-missing-method');
        }

        $bucket = $config->getBucketById($bucketIdentifier);
        if ($bucket === null) {
            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST,
                'bucketID is not configured', $errorPrefix.'-bucket-id-not-configured');
        }

        $now = BlobUtils::now();
        $linkExpiryInterval = new \DateInterval($bucket->getLinkExpireTime());

        if ($expiryDuration) {
            try {
                $requestedExpiryInterval = new \DateInterval($expiryDuration);
            } catch (\Exception) {
                throw new ApiError(Response::HTTP_BAD_REQUEST, 'Parameter expireIn is in a bad format!');
            }
            if ($now->add($requestedExpiryInterval) > $now->add($linkExpiryInterval)) {
                throw new ApiError(Response::HTTP_BAD_REQUEST, 'expireIn is too big!');
            }
            $linkExpiryInterval = $requestedExpiryInterval;
        }

        try {
            $creationDateTime = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $creationTime);
            if ($creationDateTime === false) {
                // RFC3339_EXTENDED is broken in PHP
                $creationDateTime = \DateTimeImmutable::createFromFormat("Y-m-d\TH:i:s.uP", $creationTime);
                if ($creationDateTime === false) {
                    throw ApiError::withDetails(Response::HTTP_FORBIDDEN,
                        'Parameter creationTime is in a bad format!', 'blob:check-signature-creation-time-bad-format');
                }
            }
        } catch (\ValueError) {
            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST,
                'Parameter creationTime is in a bad format!', 'blob:check-signature-creation-time-bad-format');
        }

        $expiryTime = $now->sub($linkExpiryInterval);
        $futureBlock = $now->add($linkExpiryInterval);

        // check if the request is expired or in the future
        if ($creationDateTime < $expiryTime || $creationDateTime > $futureBlock) {
            throw ApiError::withDetails(Response::HTTP_FORBIDDEN,
                'Parameter creationTime too old', $errorPrefix.'-creation-time-too-old');
        }

        // Exclude the deployment prefix while preserving the signed query's original encoding and order.
        $requestUri = $request->getPathInfo().(strstr($request->getRequestUri(), '?') ?: '');
        if (false === SignatureUtils::isSignatureValid($bucket->getKey(), $signature, $requestUri)) {
            throw ApiError::withDetails(Response::HTTP_FORBIDDEN, 'Signature invalid', 'blob:signature-invalid');
        }

        // Treat HEAD with a GET signature as GET for both method matching and the allowlist.
        $method = $request->getMethod();
        if ($method === 'HEAD' && $urlMethod === 'GET') {
            $method = 'GET';
        }
        if ($urlMethod !== $method || !in_array($method, $allowedMethods, true)) {
            throw ApiError::withDetails(Response::HTTP_METHOD_NOT_ALLOWED,
                'method is not suitable', $errorPrefix.'-method-not-suitable');
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
            if (array_key_exists('ucs', $payload) && $payload['ucs'] === SignatureTools::generateSha256Checksum($url[0])) {
                return true;
            }
        } catch (\Exception) {
        }

        return false;
    }
}
