<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Helper;

use Dbp\Relay\CoreBundle\Exception\ApiError;
use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\JWK;
use Jose\Component\KeyManagement\JWKFactory;
use Jose\Component\Signature\Algorithm\HS256;
use Jose\Component\Signature\JWSBuilder;
use Jose\Component\Signature\JWSVerifier;
use Jose\Component\Signature\Serializer\CompactSerializer;
use Jose\Component\Signature\Serializer\JWSSerializerManager;
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
        $jwk = self::createJWK($secret);

        return self::generateToken($jwk, $payload);
    }

    /**
     * Verify a JWS token.
     *
     * @param string $secret to create the (symmetric) JWK from
     * @param string $token  to verify
     *
     * @return array extracted payload from token
     *
     * @throws \JsonException
     * @throws ApiError
     */
    public static function verify(string $secret, string $token): array
    {
        $jwk = self::createJWK($secret);
        $payload = [];

        if (!self::verifyToken($jwk, $token, $payload)) {
            /* @noinspection ForgottenDebugOutputInspection */
            //dump(['token' => $token, 'payload' => $payload, 'secret' => $secret]);
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
        return hash('sha256', $url[0]);
    }

    /**
     * Create the JWK from a shared secret.
     *
     * @param string $secret to create the (symmetric) JWK from
     */
    public static function createJWK(string $secret): JWK
    {
        return JWKFactory::createFromSecret(
            $secret,
            [
                'alg' => 'HS256',
                'use' => 'sig',
            ]
        );
    }

    /**
     * Generate the token.
     *
     * @param JWK   $jwk     json web key
     * @param array $payload as json string to secure
     *
     * @return string secure token
     *
     * @throws \JsonException
     */
    public static function generateToken(JWK $jwk, array $payload): string
    {
        $algorithmManager = new AlgorithmManager([new HS256()]);
        $jwsBuilder = new JWSBuilder($algorithmManager);

        $jws = $jwsBuilder
            ->create()
            ->withPayload(json_encode($payload, JSON_THROW_ON_ERROR))
            ->addSignature($jwk, ['alg' => 'HS256'])
            ->build();

        return (new CompactSerializer())->serialize($jws, 0);
    }

    /**
     * Verify a JWS token.
     *
     * @param string $token   the JWS token as string
     * @param array  $payload to extract from token on success
     *
     * @throws \JsonException
     */
    public static function verifyToken(JWK $jwk, string $token, array &$payload): bool
    {
        $algorithmManager = new AlgorithmManager([new HS256()]);
        $jwsVerifier = new JWSVerifier($algorithmManager);
        $serializerManager = new JWSSerializerManager([new CompactSerializer()]);
        $jws = $serializerManager->unserialize($token);

        if ($ok = $jwsVerifier->verifyWithKey($jws, $jwk, 0)) {
            $payload = json_decode($jws->getPayload(), true, 512, JSON_THROW_ON_ERROR);
        }
//        $ok = $jwsVerifier->verifyWithKey($jws, $jwk, 0);
//        $payload = json_decode($jws->getPayload(), true, 512, JSON_THROW_ON_ERROR);

        return $ok;
    }
}
