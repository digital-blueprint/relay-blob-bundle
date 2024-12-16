<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Tests;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use Dbp\Relay\BlobBundle\Entity\FileData;
use Dbp\Relay\BlobBundle\Service\BlobService;
use Dbp\Relay\BlobBundle\TestUtils\BlobTestUtils;
use Dbp\Relay\BlobBundle\TestUtils\TestDatasystemProviderService;
use Dbp\Relay\BlobBundle\TestUtils\TestEntityManager;
use Symfony\Component\HttpFoundation\File\File;

class BlobServiceTest extends ApiTestCase
{
    private const TEST_PREFIX = 'test_prefix';
    private const TEST_FILE_NAME = 'test.txt';
    private const TEST_FILE_2_NAME = 'test_patch.txt';

    private const TEST_METADATA = '{"foo":"bar"}';

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
        $this->blobService = BlobTestUtils::createTestBlobService(
            $this->testEntityManager->getEntityManager(),
            $this->testConfig
        );
    }

    /**
     * @throws \Exception
     */
    public function testAddFile(): void
    {
        $testBucketConfig = self::getTestBucketConfig();
        $fileData = $this->addTestFile();
        $file = new File(__DIR__.'/'.self::TEST_FILE_NAME, true);

        $this->assertNotEmpty($fileData->getIdentifier());
        $this->assertEquals(self::TEST_PREFIX, $fileData->getPrefix());
        $this->assertEquals(self::TEST_FILE_NAME, $fileData->getFileName());
        $this->assertEquals($testBucketConfig['bucket_id'], $fileData->getBucketId());
        $this->assertEquals($testBucketConfig['internal_bucket_id'], $fileData->getInternalBucketId());
        $this->assertEquals(self::TEST_METADATA, $fileData->getMetadata());
        $this->assertEquals('text/plain', $fileData->getMimeType());
        $this->assertEquals($file->getSize(), $fileData->getFileSize());
        $this->assertNull($fileData->getFileHash());
        $this->assertNull($fileData->getMetadataHash());
        $this->assertNotNull($fileData->getDateCreated());
        $this->assertNotNull($fileData->getDateModified());
        $this->assertNotNull($fileData->getLastAccess());
        $this->assertNull($fileData->getDeleteAt());
        $this->assertNull($fileData->getNotifyEmail());
        $this->assertNull($fileData->getContentUrl());

        $this->assertTrue($this->blobService->getDatasystemProvider($fileData)->hasFile($fileData->getInternalBucketId(), $fileData->getIdentifier()));
        $this->assertTrue(TestDatasystemProviderService::isContentEqual(
            $testBucketConfig['internal_bucket_id'],
            $fileData->getIdentifier(),
            $file
        ));

        $fileIdentifier = $fileData->getIdentifier();
        $fileData = $this->testEntityManager->getFileDataById($fileIdentifier);
        $this->assertNotNull($fileData);
        $this->assertEquals($fileIdentifier, $fileData->getIdentifier());
        $this->assertEquals(self::TEST_PREFIX, $fileData->getPrefix());
        $this->assertEquals(self::TEST_FILE_NAME, $fileData->getFileName());
        $this->assertEquals($testBucketConfig['bucket_id'], $fileData->getBucketId());
        $this->assertEquals($testBucketConfig['internal_bucket_id'], $fileData->getInternalBucketId());
        $this->assertEquals(self::TEST_METADATA, $fileData->getMetadata());
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

        $file = new File(__DIR__.'/'.self::TEST_FILE_NAME, true);
        $this->assertEquals(hash('sha256', $file->getContent()), $fileData->getFileHash());
    }

    /**
     * @throws \Exception
     */
    public function testGetFile(): void
    {
        $testBucketConfig = self::getTestBucketConfig();
        $originalFileData = $this->addTestFile();

        $fileData = $this->blobService->getFile($originalFileData->getIdentifier());
        $file = new File(__DIR__.'/'.self::TEST_FILE_NAME, true);

        $this->assertNotEmpty($fileData->getIdentifier());
        $this->assertEquals(self::TEST_PREFIX, $fileData->getPrefix());
        $this->assertEquals(self::TEST_FILE_NAME, $fileData->getFileName());
        $this->assertEquals($testBucketConfig['bucket_id'], $fileData->getBucketId());
        $this->assertEquals($testBucketConfig['internal_bucket_id'], $fileData->getInternalBucketId());
        $this->assertEquals(self::TEST_METADATA, $fileData->getMetadata());
        $this->assertEquals('text/plain', $fileData->getMimeType());
        $this->assertEquals($file->getSize(), $fileData->getFileSize());
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

        $originalFileData = $this->addTestFile();
        $fileData = $this->blobService->getFile($originalFileData->getIdentifier());

        $this->assertEquals(hash('sha256', $file->getContent()), $fileData->getFileHash());
    }

    /**
     * @throws \Exception
     */
    public function testUpdateFile(): void
    {
        $testBucketConfig = self::getTestBucketConfig();
        $fileData = $this->addTestFile();
        $fileIdentifier = $fileData->getIdentifier();
        $previousFileData = clone $fileData;

        $newFile = new File(__DIR__.'/'.self::TEST_FILE_2_NAME, true);
        $fileData->setFile($newFile);
        $fileData->setFilename($newFile->getFilename());

        $fileData = $this->blobService->updateFile($fileData, $previousFileData);

        $this->assertEquals($fileIdentifier, $fileData->getIdentifier());
        $this->assertEquals(self::TEST_PREFIX, $fileData->getPrefix());
        $this->assertEquals(self::TEST_FILE_2_NAME, $fileData->getFileName());
        $this->assertEquals($testBucketConfig['bucket_id'], $fileData->getBucketId());
        $this->assertEquals($testBucketConfig['internal_bucket_id'], $fileData->getInternalBucketId());
        $this->assertEquals(self::TEST_METADATA, $fileData->getMetadata());
        $this->assertEquals('text/plain', $fileData->getMimeType());
        $this->assertEquals($newFile->getSize(), $fileData->getFileSize());
        $this->assertNull($fileData->getFileHash());
        $this->assertNull($fileData->getMetadataHash());
        $this->assertNotNull($fileData->getDateCreated());
        $this->assertNotNull($fileData->getDateModified());
        $this->assertNotNull($fileData->getLastAccess());
        $this->assertNull($fileData->getDeleteAt());
        $this->assertNull($fileData->getNotifyEmail());
        $this->assertNull($fileData->getContentUrl());

        $this->assertTrue($this->blobService->getDatasystemProvider($fileData)->hasFile($fileData->getInternalBucketId(), $fileData->getIdentifier()));
        $this->assertTrue(TestDatasystemProviderService::isContentEqual(
            $testBucketConfig['internal_bucket_id'],
            $fileData->getIdentifier(),
            $newFile
        ));

        $fileData = $this->testEntityManager->getFileDataById($fileData->getIdentifier());
        $this->assertNotNull($fileData);
        $this->assertEquals($fileIdentifier, $fileData->getIdentifier());
        $this->assertEquals(self::TEST_PREFIX, $fileData->getPrefix());
        $this->assertEquals(self::TEST_FILE_2_NAME, $fileData->getFileName());
        $this->assertEquals($testBucketConfig['bucket_id'], $fileData->getBucketId());
        $this->assertEquals($testBucketConfig['internal_bucket_id'], $fileData->getInternalBucketId());
        $this->assertEquals(self::TEST_METADATA, $fileData->getMetadata());
        $this->assertEquals('text/plain', $fileData->getMimeType());
        $this->assertEquals($newFile->getSize(), $fileData->getFileSize());
        $this->assertNull($fileData->getFileHash());
        $this->assertNull($fileData->getMetadataHash());
        $this->assertNotNull($fileData->getDateCreated());
        $this->assertNotNull($fileData->getDateModified());
        $this->assertNotNull($fileData->getLastAccess());
        $this->assertNull($fileData->getDeleteAt());
        $this->assertNull($fileData->getNotifyEmail());
        $this->assertNull($fileData->getContentUrl());
    }

    /**
     * @throws \Exception
     */
    public function testRemoveFile(): void
    {
        $testBucketConfig = self::getTestBucketConfig();
        $fileData = $this->addTestFile();
        $this->blobService->removeFile($fileData->getIdentifier(), $fileData);

        $this->assertFalse($this->blobService->getDatasystemProvider($fileData)->hasFile($fileData->getInternalBucketId(), $fileData->getIdentifier()));
        $this->assertNull($this->testEntityManager->getFileDataById($fileData->getIdentifier()));

        $fileData = $this->addTestFile();
        // ID only:
        $this->blobService->removeFile($fileData->getIdentifier());

        $this->assertFalse($this->blobService->getDatasystemProvider($fileData)->hasFile($fileData->getInternalBucketId(), $fileData->getIdentifier()));
        $this->assertNull($this->testEntityManager->getFileDataById($fileData->getIdentifier()));
    }

    /**
     * @throws \Exception
     */
    protected function addTestFile(): FileData
    {
        $testBucketConfig = self::getTestBucketConfig();

        $file = new File(__DIR__.'/'.self::TEST_FILE_NAME, true);
        $fileData = new FileData();
        $fileData->setFile($file);
        $fileData->setFilename($file->getFilename());
        $fileData->setPrefix(self::TEST_PREFIX);
        $fileData->setBucketId($testBucketConfig['bucket_id']);
        $fileData->setMetadata(self::TEST_METADATA);

        return $this->blobService->addFile($fileData);
    }

    protected static function getTestBucketConfig(int $index = 0): ?array
    {
        return BlobTestUtils::getTestConfig()['buckets'][$index] ?? null;
    }
}
