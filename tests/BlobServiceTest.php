<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Tests;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use Dbp\Relay\BlobBundle\Entity\FileData;
use Dbp\Relay\BlobBundle\Service\BlobService;
use Dbp\Relay\BlobBundle\TestUtils\BlobTestUtils;
use Dbp\Relay\BlobBundle\TestUtils\TestEntityManager;
use Symfony\Component\HttpFoundation\File\File;

class BlobServiceTest extends ApiTestCase
{
    private ?TestEntityManager $testEntityManager = null;
    private ?BlobService $blobService = null;
    private ?array $testConfig = null;

    protected function setUp(): void
    {
        $this->testConfig = null;
        $this->setUpBlobService();
    }

    protected function setUpBlobService(): void
    {
        $this->testEntityManager = new TestEntityManager(self::bootKernel()->getContainer());
        $this->blobService = BlobTestUtils::createTestBlobService($this->testEntityManager->getEntityManager(),
            $this->testConfig);
    }

    /**
     * @throws \Exception
     */
    public function testAddFile(): void
    {
        $testBucketConfig = self::getTestBucketConfig();
        $prefix = 'foo';
        $file = new File(__DIR__.'/test.txt', true);
        $fileData = new FileData();
        $fileData->setFile($file);
        $fileData->setFilename($file->getFilename());
        $fileData->setPrefix($prefix);
        $fileData->setBucketId($testBucketConfig['bucket_id']);

        $fileData = $this->blobService->addFile($fileData);
        $this->assertNotEmpty($fileData->getIdentifier());
        $this->assertEquals($prefix, $fileData->getPrefix());
        $this->assertEquals($file->getFilename(), $fileData->getFileName());
        $this->assertEquals($testBucketConfig['bucket_id'], $fileData->getBucketId());
        $this->assertEquals($testBucketConfig['internal_bucket_id'], $fileData->getInternalBucketID());
        $this->assertNull($fileData->getMetadata());
        $this->assertEquals('text/plain', $fileData->getMimeType());
        $this->assertEquals(12, $fileData->getFileSize());
        $this->assertNull($fileData->getFileHash());
        $this->assertNull($fileData->getMetadataHash());
        $this->assertNotNull($fileData->getDateCreated());
        $this->assertNotNull($fileData->getDateModified());
        $this->assertNotNull($fileData->getLastAccess());
        $this->assertNull($fileData->getDeleteAt());
        $this->assertNull($fileData->getNotifyEmail());
        $this->assertNull($fileData->getContentUrl());

        $this->testConfig = BlobTestUtils::getTestConfig();
        $this->testConfig['file_integrity_checks'] = true;
        $this->setUpBlobService();

        $fileData = $this->blobService->addFile($fileData);
        $this->assertEquals(hash('sha256', $file->getContent()), $fileData->getFileHash());
    }

    protected static function getTestBucketConfig(int $index = 0): ?array
    {
        return BlobTestUtils::getTestConfig()['buckets'][$index] ?? null;
    }
}
