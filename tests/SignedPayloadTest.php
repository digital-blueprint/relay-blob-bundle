<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Tests;

use Dbp\Relay\BlobBundle\Helper\SignatureUtils;
use PHPUnit\Framework\TestCase;

class SignedPayloadTest extends TestCase
{
    public function testEmptyPayload(): void
    {
        try {
            $secret = bin2hex(random_bytes(32));
            $bucketID = '9876';
            $creationTime = date('U');
            $payload = [
                'bucketID' => $bucketID,
                'creationTime' => $creationTime,
                'prefix' => 'MyApp/MyShare',
            ];

            $token = SignatureUtils::createSignature($secret, $payload);

            $data = SignatureUtils::verifySignature($secret, $token);
            $this->assertIsArray($data);
            $this->assertEquals($payload['bucketID'], $data['bucketID']);
            $this->assertEquals($payload['creationTime'], $data['creationTime']);
            $this->assertEquals($payload['prefix'], $data['prefix']);
        } catch (\Exception $e) {
            $this->fail($e->getMessage());
        }
    }

    public function testPayload(): void
    {
        try {
            $secret = bin2hex(random_bytes(32));
            $bucketID = '9876';
            $creationTime = date('U');
            $payload = [
                'bucketID' => $bucketID,
                'creationTime' => $creationTime,
                'prefix' => 'MyApp/MyShare',
                'filename' => 'text.txt',
                'file' => hash('sha256', file_get_contents(__DIR__.'/test.txt')),
                'metadata' => [],
            ];

            $token = SignatureUtils::createSignature($secret, $payload);

            $data = SignatureUtils::verifySignature($secret, $token);
            $this->assertEquals($payload['bucketID'], $data['bucketID']);
            $this->assertEquals($payload['creationTime'], $data['creationTime']);
            $this->assertEquals($payload['prefix'], $data['prefix']);
            $this->assertEquals($payload['filename'], $data['filename']);
            $this->assertEquals($payload['file'], $data['file']);
            $this->assertEquals($payload['metadata'], $data['metadata']);
        } catch (\Exception $e) {
            $this->fail($e->getMessage());
        }
    }

    public function testEmptyUrl(): void
    {
        try {
            $secret = bin2hex(random_bytes(32));
            $bucketID = '9876';
            $creationTime = date('U');
            $payload = [
                'bucketID' => $bucketID,
                'creationTime' => $creationTime,
                'prefix' => 'MyApp/MyShare',
            ];

            $token = SignatureUtils::createSignature($secret, $payload);

            $data = SignatureUtils::verifySignature($secret, $token);
            $this->assertIsArray($data);
            $this->assertEquals($payload['bucketID'], $data['bucketID']);
            $this->assertEquals($payload['creationTime'], $data['creationTime']);
            $this->assertEquals($payload['prefix'], $data['prefix']);
        } catch (\Exception $e) {
            $this->fail($e->getMessage());
        }
    }
}
