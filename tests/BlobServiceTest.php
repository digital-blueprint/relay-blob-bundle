<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Tests;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use Dbp\Relay\BlobBundle\Entity\FileData;
use Dbp\Relay\BlobBundle\Service\BlobService;
use Dbp\Relay\BlobBundle\TestUtils\BlobTestUtils;
use Dbp\Relay\BlobBundle\TestUtils\TestEntityManager;
use Dbp\Relay\CoreBundle\Rest\Query\Filter\FilterTreeBuilder;
use Symfony\Component\HttpFoundation\File\File;

class BlobServiceTest extends ApiTestCase
{
    private const TEST_PREFIX = 'test_prefix';
    private const TEST_FILE_NAME = 'test.txt';
    private const TEST_FILE_2_NAME = 'test_patch.txt';

    private const TEST_METADATA = '{"foo":"bar"}';
    private const TEST_METADATA_2 = '{"bar":"baz"}';

    private ?TestEntityManager $testEntityManager = null;
    private ?BlobService $blobService = null;
    private ?array $testConfig = null;

    protected function setUp(): void
    {
        $this->testConfig = null;
        $this->setUpBlobService();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        BlobTestUtils::tearDown();
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
        $filePath = __DIR__.'/'.self::TEST_FILE_NAME;
        $metadata = self::TEST_METADATA;
        $fileData = $this->addTestFile(filePath: $filePath, metadata: $metadata);
        $file = new File($filePath, true);

        $this->assertNotEmpty($fileData->getIdentifier());
        $this->assertEquals(self::TEST_PREFIX, $fileData->getPrefix());
        $this->assertEquals(self::TEST_FILE_NAME, $fileData->getFileName());
        $this->assertEquals($testBucketConfig['bucket_id'], $fileData->getBucketId());
        $this->assertEquals($testBucketConfig['internal_bucket_id'], $fileData->getInternalBucketId());
        $this->assertEquals(self::TEST_METADATA, $fileData->getMetadata());
        $this->assertEquals('text/plain', $fileData->getMimeType());
        $this->assertEquals($file->getSize(), $fileData->getFileSize());
        $this->assertEquals(hash_file('sha256', $filePath), $fileData->getFileHash());
        $this->assertEquals(hash('sha256', $metadata), $fileData->getMetadataHash());
        $this->assertNotNull($fileData->getDateCreated());
        $this->assertNotNull($fileData->getDateModified());
        $this->assertNotNull($fileData->getDateAccessed());
        $this->assertNull($fileData->getDeleteAt());
        $this->assertNull($fileData->getNotifyEmail());
        $this->assertNotNull($fileData->getContentUrl());

        $provider = $this->blobService->getDatasystemProvider($fileData->getInternalBucketId());
        $this->assertTrue($provider->hasFile($fileData->getInternalBucketId(), $fileData->getIdentifier()));

        $this->assertSame($this->blobService->getFileContents($fileData), $file->getContent());

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
        $this->assertEquals(hash_file('sha256', $filePath), $fileData->getFileHash());
        $this->assertEquals(hash('sha256', $metadata), $fileData->getMetadataHash());
        $this->assertNotNull($fileData->getDateCreated());
        $this->assertNotNull($fileData->getDateModified());
        $this->assertNotNull($fileData->getDateAccessed());
        $this->assertNull($fileData->getDeleteAt());
        $this->assertNull($fileData->getNotifyEmail());
        $this->assertNotNull($fileData->getContentUrl());

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
        $filePath = __DIR__.'/'.self::TEST_FILE_NAME;
        $metadata = self::TEST_METADATA;
        $originalFileData = $this->addTestFile(filePath: $filePath, metadata: $metadata);

        $fileData = $this->blobService->getFileData($originalFileData->getIdentifier());
        $file = new File($filePath, true);

        $this->assertNotEmpty($fileData->getIdentifier());
        $this->assertEquals(self::TEST_PREFIX, $fileData->getPrefix());
        $this->assertEquals(self::TEST_FILE_NAME, $fileData->getFileName());
        $this->assertEquals($testBucketConfig['bucket_id'], $fileData->getBucketId());
        $this->assertEquals($testBucketConfig['internal_bucket_id'], $fileData->getInternalBucketId());
        $this->assertEquals(self::TEST_METADATA, $fileData->getMetadata());
        $this->assertEquals('text/plain', $fileData->getMimeType());
        $this->assertEquals($file->getSize(), $fileData->getFileSize());
        $this->assertStringStartsWith('/blob/files/'.$fileData->getIdentifier().'/download?', $fileData->getContentUrl());
        $this->assertEquals(hash_file('sha256', $filePath), $fileData->getFileHash());
        $this->assertEquals(hash('sha256', $metadata), $fileData->getMetadataHash());
        $this->assertNotNull($fileData->getDateCreated());
        $this->assertNotNull($fileData->getDateModified());
        $this->assertNotNull($fileData->getDateAccessed());
        $this->assertNull($fileData->getDeleteAt());
        $this->assertNull($fileData->getNotifyEmail());

        $this->testConfig = BlobTestUtils::getTestConfig();
        $this->testConfig['file_integrity_checks'] = true;
        $this->setUpBlobService();

        $originalFileData = $this->addTestFile();
        $fileData = $this->blobService->getFileData($originalFileData->getIdentifier());

        $this->assertEquals(hash('sha256', $file->getContent()), $fileData->getFileHash());

        $fileData = $this->blobService->getFileData($originalFileData->getIdentifier(), [BlobService::BASE_URL_OPTION => 'http://www.example.com']);
        $this->assertStringStartsWith('http://www.example.com/blob/files/'.$fileData->getIdentifier().'/download?', $fileData->getContentUrl());
    }

    /**
     * @throws \Exception
     */
    public function testUpdateFile(): void
    {
        $testBucketConfig = self::getTestBucketConfig();

        $fileData = $this->addTestFile(filePath: __DIR__.'/'.self::TEST_FILE_NAME, metadata: self::TEST_METADATA);
        $fileIdentifier = $fileData->getIdentifier();
        $previousFileData = clone $fileData;

        $filePath = __DIR__.'/'.self::TEST_FILE_2_NAME;
        $metadata = self::TEST_METADATA_2;
        $newFile = new File($filePath, true);
        $fileData->setFile($newFile);
        $fileData->setFilename($newFile->getFilename());
        $fileData->setMetadata($metadata);

        $fileData = $this->blobService->updateFile($fileData, $previousFileData);

        $this->assertEquals($fileIdentifier, $fileData->getIdentifier());
        $this->assertEquals(self::TEST_PREFIX, $fileData->getPrefix());
        $this->assertEquals(self::TEST_FILE_2_NAME, $fileData->getFileName());
        $this->assertEquals($testBucketConfig['bucket_id'], $fileData->getBucketId());
        $this->assertEquals($testBucketConfig['internal_bucket_id'], $fileData->getInternalBucketId());
        $this->assertEquals(self::TEST_METADATA_2, $fileData->getMetadata());
        $this->assertEquals('text/plain', $fileData->getMimeType());
        $this->assertEquals($newFile->getSize(), $fileData->getFileSize());
        $this->assertEquals(hash_file('sha256', $filePath), $fileData->getFileHash());
        $this->assertEquals(hash('sha256', $metadata), $fileData->getMetadataHash());
        $this->assertNotNull($fileData->getDateCreated());
        $this->assertNotNull($fileData->getDateModified());
        $this->assertNotNull($fileData->getDateAccessed());
        $this->assertNull($fileData->getDeleteAt());
        $this->assertNull($fileData->getNotifyEmail());
        $this->assertNotNull($fileData->getContentUrl());
        $provider = $this->blobService->getDatasystemProvider($fileData->getInternalBucketId());
        $this->assertTrue($provider->hasFile($fileData->getInternalBucketId(), $fileData->getIdentifier()));

        $this->assertSame($this->blobService->getFileHashFromStorage($fileData), hash('sha256', $newFile->getContent()));
        $this->assertSame($this->blobService->getFileContents($fileData), $newFile->getContent());

        $fileData = $this->testEntityManager->getFileDataById($fileData->getIdentifier());
        $this->assertNotNull($fileData);
        $this->assertEquals($fileIdentifier, $fileData->getIdentifier());
        $this->assertEquals(self::TEST_PREFIX, $fileData->getPrefix());
        $this->assertEquals(self::TEST_FILE_2_NAME, $fileData->getFileName());
        $this->assertEquals($testBucketConfig['bucket_id'], $fileData->getBucketId());
        $this->assertEquals($testBucketConfig['internal_bucket_id'], $fileData->getInternalBucketId());
        $this->assertEquals(self::TEST_METADATA_2, $fileData->getMetadata());
        $this->assertEquals('text/plain', $fileData->getMimeType());
        $this->assertEquals($newFile->getSize(), $fileData->getFileSize());
        $this->assertEquals(hash_file('sha256', $filePath), $fileData->getFileHash());
        $this->assertEquals(hash('sha256', $metadata), $fileData->getMetadataHash());
        $this->assertNotNull($fileData->getDateCreated());
        $this->assertNotNull($fileData->getDateModified());
        $this->assertNotNull($fileData->getDateAccessed());
        $this->assertNull($fileData->getDeleteAt());
        $this->assertNull($fileData->getNotifyEmail());
        $this->assertNotNull($fileData->getContentUrl());
    }

    /**
     * @throws \Exception
     */
    public function testRemoveFile(): void
    {
        $fileData = $this->addTestFile();
        $this->blobService->removeFile($fileData);

        $this->assertFalse($this->blobService->getDatasystemProvider($fileData->getInternalBucketId())
            ->hasFile($fileData->getInternalBucketId(), $fileData->getIdentifier()));
        $this->assertNull($this->testEntityManager->getFileDataById($fileData->getIdentifier()));
    }

    /**
     * @throws \Exception
     */
    public function testGetFileDataCollectionCursorBased(): void
    {
        $fileDataIdentifiers = [];
        foreach (range(0, 31) as $i) {
            $fileDataIdentifiers[$this->addTestFile($i < 16 ? 0 : 1)->getIdentifier()] = true;
        }

        $maxNumItemsPerPage = 10;
        $fileDataIdentifiersWorkingCopy = $fileDataIdentifiers;
        $lastIdentifier = null;
        do {
            $fileDataCollection = $this->blobService->getFileDataCollectionCursorBased(
                $lastIdentifier, $maxNumItemsPerPage);
            if ($numFilesReturned = count($fileDataCollection)) {
                $lastIdentifier = $fileDataCollection[$numFilesReturned - 1]->getIdentifier();
            }
            foreach ($fileDataCollection as $fileData) {
                $this->assertArrayHasKey($fileData->getIdentifier(), $fileDataIdentifiersWorkingCopy);
                unset($fileDataIdentifiersWorkingCopy[$fileData->getIdentifier()]);
                $lastIdentifier = $fileData->getIdentifier();
            }
        } while (count($fileDataCollection) === $maxNumItemsPerPage);

        $this->assertEmpty($fileDataIdentifiersWorkingCopy);

        $fileDataIdentifiersWorkingCopy = $fileDataIdentifiers;
        $lastIdentifier = null;
        $filter = FilterTreeBuilder::create()
            ->equals('internalBucketId', self::getTestBucketConfig(1)['internal_bucket_id'])
            ->createFilter();
        do {
            $fileDataCollection = $this->blobService->getFileDataCollectionCursorBased(
                $lastIdentifier, $maxNumItemsPerPage, $filter);
            if ($numFilesReturned = count($fileDataCollection)) {
                $lastIdentifier = $fileDataCollection[$numFilesReturned - 1]->getIdentifier();
            }
            foreach ($fileDataCollection as $fileData) {
                $this->assertArrayHasKey($fileData->getIdentifier(), $fileDataIdentifiersWorkingCopy);
                unset($fileDataIdentifiersWorkingCopy[$fileData->getIdentifier()]);
                $lastIdentifier = $fileData->getIdentifier();
            }
        } while (count($fileDataCollection) === $maxNumItemsPerPage);

        $this->assertCount(16, $fileDataIdentifiersWorkingCopy);
    }

    /**
     * @throws \Exception
     */
    protected function addTestFile(int $testBucketIndex = 0, string $filePath = __DIR__.'/'.self::TEST_FILE_NAME,
        string $metadata = self::TEST_METADATA): FileData
    {
        $testBucketConfig = self::getTestBucketConfig($testBucketIndex);

        $file = new File($filePath, true);
        $fileData = new FileData();
        $fileData->setFile($file);
        $fileData->setFilename($file->getFilename());
        $fileData->setPrefix(self::TEST_PREFIX);
        $fileData->setBucketId($testBucketConfig['bucket_id']);
        $fileData->setMetadata($metadata);

        return $this->blobService->addFile($fileData);
    }

    protected static function getTestBucketConfig(int $index = 0): ?array
    {
        return BlobTestUtils::getTestConfig()['buckets'][$index] ?? null;
    }
}
