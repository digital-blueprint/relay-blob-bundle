<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Tests;

use Dbp\Relay\BlobBundle\Configuration\BucketConfig;
use Dbp\Relay\BlobBundle\Configuration\ConfigurationService;
use Dbp\Relay\BlobBundle\Helper\SignatureUtils;
use Dbp\Relay\BlobBundle\TestUtils\BlobApiTest;
use Dbp\Relay\CoreBundle\TestUtils\AbstractApiTest;
use Dbp\Relay\CoreBundle\TestUtils\UserAuthTrait;
use Symfony\Component\HttpFoundation\Response;

class ApiTest extends AbstractApiTest
{
    use UserAuthTrait;

    protected function setUp(): void
    {
        parent::setUp();

        BlobApiTest::setUp($this->testClient->getContainer());
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

    private function getTestBucketConfig(int $index = 0): BucketConfig
    {
        $configService = $this->testClient->getContainer()->get(ConfigurationService::class);

        return $configService->getBuckets()[$index];
    }
}
