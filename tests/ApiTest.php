<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Tests;

use Dbp\Relay\BlobBundle\Configuration\BucketConfig;
use Dbp\Relay\BlobBundle\Configuration\ConfigurationService;
use Dbp\Relay\BlobBundle\Helper\SignatureUtils;
use Dbp\Relay\BlobBundle\TestUtils\BlobApiTest;
use Dbp\Relay\BlobLibrary\Api\BlobFile;
use Dbp\Relay\CoreBundle\TestUtils\ApiTestCase;
use Symfony\Component\HttpFoundation\Response;

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

        $url = '/blob/files/404';
        $url = SignatureUtils::getSignedUrl($url, $bucketConfig->getKey(), $bucketConfig->getBucketId(), 'GET');

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
        $path = '/blob/files/'.$file->getIdentifier().'/download';

        $url = SignatureUtils::getSignedUrl($path, $otherBucket->getKey(), $otherBucket->getBucketId(), 'GET');
        $response = $this->testClient->get($url);
        $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());

        $url = SignatureUtils::getSignedUrl($path, $fileBucket->getKey(), $fileBucket->getBucketId(), 'GET');
        $response = $this->testClient->get($url);
        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSame('private file contents', $response->getContent());
    }

    private function getTestBucketConfig(int $index = 0): BucketConfig
    {
        $configService = $this->getContainer()->get(ConfigurationService::class);

        return $configService->getBuckets()[$index];
    }
}
