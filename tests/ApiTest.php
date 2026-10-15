<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Tests;

use Dbp\Relay\BlobBundle\Configuration\BucketConfig;
use Dbp\Relay\BlobBundle\Configuration\ConfigurationService;
use Dbp\Relay\BlobBundle\Entity\BucketLock;
use Dbp\Relay\BlobBundle\Entity\MetadataBackupJob;
use Dbp\Relay\BlobBundle\Service\BlobService;
use Dbp\Relay\BlobBundle\TestUtils\BlobApiTest;
use Dbp\Relay\BlobLibrary\Api\BlobFile;
use Dbp\Relay\BlobLibrary\Helpers\SignatureTools;
use Dbp\Relay\CoreBundle\TestUtils\ApiTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\HttpClient\ResponseInterface;

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
        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());

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

    public function testCrossBucketAndMissingFilesHaveSameError(): void
    {
        $requestBucket = $this->getTestBucketConfig();
        $fileBucket = $this->getTestBucketConfig(1);
        $fileApi = self::getContainer()->get('dbp.relay.blob.file_api');
        $file = $fileApi->addFile($fileBucket->getBucketId(),
            new BlobFile(['prefix' => 'private', 'fileName' => 'private.txt'], 'private file contents'));

        foreach ([$file->getIdentifier(), (string) Uuid::v7()] as $identifier) {
            $url = SignatureTools::createSignedUrl($requestBucket->getBucketId(), $requestBucket->getKey(), 'GET',
                identifier: $identifier);
            $response = $this->testClient->get($url);
            $this->assertErrorWithoutId($response, Response::HTTP_NOT_FOUND);
            $this->assertSame('FileData was not found!', $response->toArray(false)['detail']);
        }
    }

    public function testMissingJobsAndLocksHaveNoErrorId(): void
    {
        $this->login(userAttributes: ['SCOPE_BLOB_METADATA_BACKUP_AND_RESTORE' => true]);

        foreach (['metadata-backup-jobs', 'metadata-restore-jobs', 'bucket-locks'] as $resource) {
            $response = $this->testClient->get('/blob/'.$resource.'/'.Uuid::v7());
            $this->assertErrorWithoutId($response, Response::HTTP_NOT_FOUND);
        }
    }

    public function testMissingJobForCancellationHasNoErrorId(): void
    {
        $this->login(userAttributes: ['SCOPE_BLOB_METADATA_BACKUP_AND_RESTORE' => true]);

        foreach (['metadata-backup-jobs', 'metadata-restore-jobs'] as $resource) {
            $response = $this->testClient->postJson('/blob/'.$resource.'/'.Uuid::v7().'/cancel', new \stdClass());
            $this->assertErrorWithoutId($response, Response::HTTP_NOT_FOUND);
        }
    }

    public function testInvalidResourceQueryParametersAreBadRequestsWithoutErrorId(): void
    {
        $this->login(userAttributes: ['SCOPE_BLOB_METADATA_BACKUP_AND_RESTORE' => true]);

        $response = $this->testClient->get('/blob/bucket-locks?bucketIdentifier=missing-bucket');
        $this->assertErrorWithoutId($response, Response::HTTP_BAD_REQUEST);

        $response = $this->testClient->postJson('/blob/bucket-locks?bucketIdentifier=missing-bucket', new \stdClass());
        $this->assertErrorWithoutId($response, Response::HTTP_BAD_REQUEST);
    }

    public function testRestoreWithMissingBackupJobIsNotFound(): void
    {
        $this->login(userAttributes: ['SCOPE_BLOB_METADATA_BACKUP_AND_RESTORE' => true]);

        $response = $this->testClient->postJson('/blob/metadata-restore-jobs?metadataBackupJobId='.Uuid::v7(), new \stdClass());
        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    public function testRestoreWithUnfinishedBackupRemainsBadRequest(): void
    {
        $this->login(userAttributes: ['SCOPE_BLOB_METADATA_BACKUP_AND_RESTORE' => true]);
        $job = new MetadataBackupJob();
        $blobService = $this->getContainer()->get(BlobService::class);
        $blobService->setupMetadataBackupJob($job, $this->getTestBucketConfig()->getInternalBucketId());

        $response = $this->testClient->postJson('/blob/metadata-restore-jobs?metadataBackupJobId='.$job->getIdentifier(), new \stdClass());
        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testMissingJobsRequirePermissionBeforeLookup(): void
    {
        $this->login(userAttributes: ['SCOPE_BLOB_METADATA_BACKUP_AND_RESTORE' => false]);

        foreach (['metadata-backup-jobs', 'metadata-restore-jobs'] as $resource) {
            $response = $this->testClient->postJson('/blob/'.$resource.'/'.Uuid::v7().'/cancel', new \stdClass());
            $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        }

        $response = $this->testClient->postJson('/blob/metadata-restore-jobs?metadataBackupJobId='.Uuid::v7(), new \stdClass());
        $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    private function assertErrorWithoutId(ResponseInterface $response, int $statusCode): void
    {
        $this->assertSame($statusCode, $response->getStatusCode());
        $error = $response->toArray(false);
        $this->assertNotEmpty($error['detail']);
        $this->assertArrayNotHasKey('relay:errorId', $error);
        $this->assertArrayNotHasKey('relay:errorDetails', $error);
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
