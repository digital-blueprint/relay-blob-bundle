<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Tests;

use Dbp\Relay\BlobBundle\Configuration\BucketConfig;
use Dbp\Relay\BlobBundle\Configuration\ConfigurationService;
use Dbp\Relay\BlobBundle\Entity\BucketLock;
use Dbp\Relay\BlobBundle\Service\BlobService;
use Dbp\Relay\BlobBundle\TestUtils\BlobApiTest;
use Dbp\Relay\BlobLibrary\Api\BlobFile;
use Dbp\Relay\BlobLibrary\Helpers\SignatureTools;
use Dbp\Relay\CoreBundle\TestUtils\ApiTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

class ApiTest extends ApiTestCase
{
    protected function setUp(): void
    {
        $this->createTestClient();
        $this->login();

        BlobApiTest::setUp($this->getContainer());
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        BlobApiTest::tearDown();
    }

    public function testGetFileDataByIdNotFound(): void
    {
        $bucketConfig = self::getTestBucketConfig();

        $url = SignatureTools::createSignedUrl($bucketConfig->getBucketId(), $bucketConfig->getKey(), 'GET', identifier: '404');

        $response = $this->testClient->get($url);
        $this->assertEquals(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    public function testDownloadRequiresMatchingBucket(): void
    {
        $otherBucket = $this->getTestBucketConfig();
        $fileBucket = $this->getTestBucketConfig(1);
        $this->assertNotSame($otherBucket->getKey(), $fileBucket->getKey());

        $file = new BlobFile();
        $file->setPrefix('private');
        $file->setFileName('private.txt');
        $file->setFile('private file contents');
        $fileApi = self::getContainer()->get('dbp.relay.blob.file_api');
        $file = $fileApi->addFile($fileBucket->getBucketId(), $file);
        $url = SignatureTools::createSignedUrl($otherBucket->getBucketId(), $otherBucket->getKey(), 'GET',
            identifier: $file->getIdentifier(), action: 'download');
        $response = $this->testClient->get($url);
        $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());

        $url = SignatureTools::createSignedUrl($fileBucket->getBucketId(), $fileBucket->getKey(), 'GET',
            identifier: $file->getIdentifier(), action: 'download');
        $response = $this->testClient->get($url);
        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSame('private file contents', $response->getContent());
    }

    public function testDownloadRespectsReadLock(): void
    {
        $bucket = $this->getTestBucketConfig();
        $file = new BlobFile(['prefix' => 'locked', 'fileName' => 'locked.txt'], 'locked file contents');
        $fileApi = self::getContainer()->get('dbp.relay.blob.file_api');
        $file = $fileApi->addFile($bucket->getBucketId(), $file);
        $url = SignatureTools::createSignedUrl($bucket->getBucketId(), $bucket->getKey(), 'GET',
            identifier: $file->getIdentifier(), action: 'download');

        $lock = new BucketLock();
        $lock->setIdentifier((string) Uuid::v7());
        $lock->setInternalBucketId($bucket->getInternalBucketId());
        $lock->setGetLock(true);
        $blobService = self::getContainer()->get(BlobService::class);
        $blobService->addBucketLock($lock);

        $response = $this->testClient->get($url);
        $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        $response = $this->testClient->request('HEAD', $url);
        $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());

        $blobService->removeBucketLock($lock->getIdentifier());
        $response = $this->testClient->get($url);
        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSame('locked file contents', $response->getContent());
    }

    public function testMalformedSignatureParametersAreRejected(): void
    {
        $parameters = [
            'bucketIdentifier' => $this->getTestBucketConfig()->getBucketId(),
            'creationTime' => date('c'),
            'method' => 'GET',
            'sig' => 'invalid',
        ];
        $malformedParameters = [
            'signature array' => ['sig' => ['invalid']],
            'invalid expiry' => ['expireIn' => 'invalid'],
            'null byte in creation time' => ['creationTime' => date('c')."\0"],
        ];

        foreach ($malformedParameters as $description => $override) {
            $url = '/blob/files/404/download?'.http_build_query(array_replace($parameters, $override));
            $response = $this->testClient->get($url);
            $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode(), $description);
        }
    }

    private function getTestBucketConfig(int $index = 0): BucketConfig
    {
        $configService = $this->getContainer()->get(ConfigurationService::class);

        return $configService->getBuckets()[$index];
    }
}
