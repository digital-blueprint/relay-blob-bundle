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

        if ($expiryDuration) {
            if ($now->add(new \DateInterval($expiryDuration)) >
                $now->add(new \DateInterval($bucket->getLinkExpireTime()))) {
                throw ApiError::withDetails(Response::HTTP_BAD_REQUEST,
                    'expireIn is too big!', $errorPrefix.'-expireIn-too-big');
            }
            $linkExpiryTime = $expiryDuration;
        } else {
            $linkExpiryTime = $bucket->getLinkExpireTime();
        }

        $creationDateTime = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $creationTime);
        if ($creationDateTime === false) {
            // RFC3339_EXTENDED is broken in PHP
            $creationDateTime = \DateTimeImmutable::createFromFormat("Y-m-d\TH:i:s.uP", $creationTime);
            if ($creationDateTime === false) {
                throw ApiError::withDetails(Response::HTTP_FORBIDDEN,
                    'Parameter creationTime is in a bad format!', 'blob:check-signature-creation-time-bad-format');
            }
        }

        $expiryTime = $now->sub(new \DateInterval($linkExpiryTime));
        $futureBlock = $now->add(new \DateInterval($linkExpiryTime));

        // check if the request is expired or in the future
        if ($creationDateTime < $expiryTime || $creationDateTime > $futureBlock) {
            throw ApiError::withDetails(Response::HTTP_FORBIDDEN,
                'Parameter creationTime too old', $errorPrefix.'-creation-time-too-old');
        }

        // check if the method specified in url and actual method used match
        $method = $request->getMethod();
        if ($urlMethod !== $method || !in_array($method, $allowedMethods, true)) {
            throw ApiError::withDetails(Response::HTTP_METHOD_NOT_ALLOWED,
                'method is not suitable', $errorPrefix.'-method-not-suitable');
        }

        if (false === SignatureUtils::isSignatureValid($bucket->getKey(), $signature, $request->getRequestUri())) {
            throw ApiError::withDetails(Response::HTTP_FORBIDDEN, 'Signature invalid', 'blob:signature-invalid');
        }
    }

    public static function getSignedUrl(string $url, string $secret, string $bucketIdentifier, string $method,
        array $additionalQueryParameters = [], ?string $creationTime = null): string
    {
        $creationTime ??= rawurlencode(date('c'));
        $url = "$url?bucketIdentifier=$bucketIdentifier&creationTime=$creationTime&method=$method";

        foreach ($additionalQueryParameters as $key => $value) {
            $url .= "&$key=".rawurlencode($value);
        }

        $payload = [
            'ucs' => SignatureTools::generateSha256Checksum($url),
        ];

        try {
            $signature = SignatureTools::createSignature($secret, $payload);
        } catch (\JsonException) {
            throw new \RuntimeException('JSON encoding payload failed');
        }

        return $url.'&sig='.$signature;
    }

    private static function generateSha256FromRequestUri(string $requestUri): string
    {
        $url = explode('&sig=', $requestUri);

        return SignatureTools::generateSha256Checksum($url[0]);
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
        try {
            $payload = SignatureTools::verifySignature($bucketKey, $signature);
            if (array_key_exists('ucs', $payload) && $payload['ucs'] === self::generateSha256FromRequestUri($requestUri)) {
                return true;
            }
        } catch (\Exception) {
        }

        return false;
    }
}
