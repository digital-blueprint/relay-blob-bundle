<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Tests;

use Dbp\Relay\BlobBundle\Helper\DenyAccessUnlessCheckSignature;
use Exception;
use PHPUnit\Framework\TestCase;

class CurlGetTest extends TestCase
{
    public function testGet(): void
    {
        try {
            $secret = '08d848fd868d83646778b87dd0695b10f59c78e23b286e9884504d1bb43cce93';
            $bucketId = '1234';
            $creationTime = date('U');
            $prefix = 'playground';
            $payload = [
                'bucketID' => $bucketId,
                'creationTime' => $creationTime,
                'prefix' => $prefix,
            ];

            $uri = "http://127.0.0.1:8000/blob/files/?bucketID=$bucketId&prefix=$prefix&creationTime=$creationTime";

            $token = DenyAccessUnlessCheckSignature::create($secret, $payload);

//            echo "Signatur: $token\n";

            $header = [
                'x-dbp-signature: '.$token,
            ];
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $uri);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
            curl_setopt($ch, CURLOPT_HEADER, 0);
            curl_setopt($ch, CURLOPT_NOBODY, 0);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            $result = curl_exec($ch);
            $info = curl_getinfo($ch);
            curl_close($ch);
            $data = json_decode($result, true, 512, JSON_THROW_ON_ERROR);
//            print_r($result);

            $this->assertEquals(200, $info['http_code']);
            $this->assertArrayHasKey('hydra:view', $data);
        } catch (Exception $e) {
            $this->fail($e->getMessage());
        }
    }
}
