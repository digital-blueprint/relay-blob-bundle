<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Tests;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use Dbp\Relay\BlobBundle\Configuration\BucketConfig;
use Dbp\Relay\BlobBundle\Configuration\ConfigurationService;
use Dbp\Relay\BlobBundle\Helper\SignatureUtils;
use Dbp\Relay\BlobBundle\TestUtils\BlobApiTest;
use Dbp\Relay\CoreBundle\TestUtils\TestAuthorizationService;
use Dbp\Relay\CoreBundle\TestUtils\TestClient;
use Dbp\Relay\CoreBundle\TestUtils\UserAuthTrait;
use Symfony\Component\HttpFoundation\Response;

class ApiTest extends ApiTestCase
{
    use UserAuthTrait;

    private TestClient $testClient;

    protected function setUp(): void
    {
        $this->testClient = new TestClient(ApiTestCase::createClient());
        $this->testClient->setUpUser(TestAuthorizationService::TEST_USER_IDENTIFIER);
        BlobApiTest::setUp($this->testClient->getContainer());
        // the following allows multiple requests in one test:
        $this->testClient->getClient()->disableReboot();
    }

    public function testGetFileDataByIdNotFound(): void
    {
        $bucketConfig = self::getTestBucketConfig();

        $url = '/blob/files/404';
        $url = SignatureUtils::getSignedUrl($url, $bucketConfig->getKey(), $bucketConfig->getBucketID(), 'GET');

        $response = $this->testClient->get($url);
        $this->assertEquals(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    private function getTestBucketConfig(int $index = 0): BucketConfig
    {
        $configService = $this->testClient->getContainer()->get(ConfigurationService::class);

        return $configService->getBuckets()[$index];
    }
}
