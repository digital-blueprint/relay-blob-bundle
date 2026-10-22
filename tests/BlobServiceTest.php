<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Tests;

use Dbp\Relay\BlobBundle\Entity\FileData;
use Dbp\Relay\BlobBundle\Service\BlobService;
use Dbp\Relay\BlobBundle\Service\DatasystemProviderServiceInterface;
use Dbp\Relay\BlobBundle\TestUtils\BlobTestUtils;
use Dbp\Relay\BlobBundle\TestUtils\TestEntityManager;
use Dbp\Relay\CoreBundle\Exception\ApiError;
use Dbp\Relay\CoreBundle\Rest\Query\Filter\FilterTreeBuilder;
use Nyholm\Psr7\Stream;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class BlobServiceTest extends KernelTestCase
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
        $this->setUpBlobService();

        $fileData = $this->blobService->addFile($fileData);

        $file = new File(__DIR__.'/'.self::TEST_FILE_NAME, true);
        $this->assertEquals(hash('sha256', $file->getContent()), $fileData->getFileHash());
    }

    /**
     * @throws \Exception
     */
    public function testAddFileWithJsonObjectMetadata(): void
    {
        $fileData = $this->addTestFile(metadata: '{"foo":"bar"}');
        $this->assertEquals('{"foo":"bar"}', $fileData->getMetadata());
    }

    public function testGetFileForFileData(): void
    {
        $file = $this->blobService->getFileForFileData($this->addTestFile());
        try {
            $this->assertSame(file_get_contents(__DIR__.'/'.self::TEST_FILE_NAME), $file->getContent());
        } finally {
            unlink($file->getPathname());
        }
    }

    public function testGetFileForFileDataCleansUpAfterCopyFailure(): void
    {
        $source = fopen('php://output', 'w');
        $provider = $this->createMock(DatasystemProviderServiceInterface::class);
        $provider->method('getFileStream')->willReturn(Stream::create($source));
        $blobService = $this->createPartialMock(BlobService::class, ['getDatasystemProvider']);
        $blobService->method('getDatasystemProvider')->willReturn($provider);
        $tempFiles = glob(sys_get_temp_dir().'/'.BlobService::TEMP_FILE_PREFIX.'*');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Could not copy file to temporary file path');
        try {
            $blobService->getFileForFileData($this->addTestFile());
        } finally {
            $this->assertFalse(is_resource($source));
            $this->assertSame($tempFiles, glob(sys_get_temp_dir().'/'.BlobService::TEMP_FILE_PREFIX.'*'));
        }
    }

    public function testExpiredFileHasNoNotFoundErrorId(): void
    {
        $file = $this->addTestFile();
        $file->setDeleteAt(new \DateTimeImmutable('-1 day'));

        try {
            $this->blobService->getFileData($file->getIdentifier());
            $this->fail('Expected an expired file to be unavailable');
        } catch (ApiError $error) {
            $this->assertSame(Response::HTTP_NOT_FOUND, $error->getStatusCode());
            $this->assertSame('FileData was not found!', $error->getMessage());
            $this->assertNull($error->getErrorId());
            $this->assertNull($error->getErrorDetails());
        }
    }

    public function testChecksumsAreStoredWithOutputValidationDisabled(): void
    {
        $this->testConfig = BlobTestUtils::getTestConfig();
        $this->testConfig['buckets'][0]['output_validation'] = false;
        $this->setUpBlobService();

        $fileData = $this->addTestFile();
        $fileData = $this->testEntityManager->getFileDataById($fileData->getIdentifier());
        $expectedFileHash = hash_file('sha256', __DIR__.'/'.self::TEST_FILE_NAME);
        $this->assertSame($expectedFileHash, $fileData->getFileHash());
        $this->assertSame(hash('sha256', self::TEST_METADATA), $fileData->getMetadataHash());

        $fileData->setFile(null);
        $previousFileData = clone $fileData;
        $fileData->setMetadata(self::TEST_METADATA_2);
        $this->blobService->updateFile($fileData, $previousFileData);

        $fileData = $this->testEntityManager->getFileDataById($fileData->getIdentifier());
        $this->assertSame($expectedFileHash, $fileData->getFileHash());
        $this->assertSame(hash('sha256', self::TEST_METADATA_2), $fileData->getMetadataHash());
    }

    public static function invalidUploadFields(): iterable
    {
        foreach (['metadata', 'fileName', 'fileHash', 'metadataHash'] as $field) {
            yield $field.' array' => [[$field => ['value']], [], $field.' must be a string'];
        }
        $file = new UploadedFile(__DIR__.'/'.self::TEST_FILE_NAME, self::TEST_FILE_NAME, test: true);
        yield 'file array' => [[], ['file' => [$file]], 'file must be a single uploaded file'];
        yield 'file as text' => [['file' => 'contents'], [], 'file must be a single uploaded file'];
        yield 'metadata as upload' => [[], ['metadata' => $file], 'metadata must be a string'];
    }

    #[DataProvider('invalidUploadFields')]
    public function testInvalidUploadFieldShapesAreBadRequest(array $body, array $files, string $message): void
    {
        $request = Request::create('/blob/files?bucketIdentifier=test-bucket', 'PATCH', $body, files: $files);
        $fileData = new FileData();
        try {
            $this->blobService->setUpFileDataFromRequest($fileData, $request);
            $this->fail('Expected invalid upload fields to be rejected');
        } catch (ApiError $error) {
            $this->assertSame(Response::HTTP_BAD_REQUEST, $error->getStatusCode());
            $this->assertSame('Body field '.$message, $error->getMessage());
            $this->assertNull($fileData->getBucketId());
        }
    }

    public function testUploadFieldShapesAllowEmptyStringsAndUnknownFields(): void
    {
        $request = Request::create('/blob/files?bucketIdentifier=test-bucket', 'PATCH',
            ['fileName' => '', 'metadata' => '', 'unknown' => ['ignored']]);

        $fileData = $this->blobService->setUpFileDataFromRequest(new FileData(), $request);
        $this->assertSame('', $fileData->getFileName());
        $this->assertSame('', $fileData->getMetadata());
    }

    public function testFileHashWithoutUploadedFileIsRejected(): void
    {
        $request = Request::create('/blob/files?bucketIdentifier=test-bucket', 'PATCH',
            ['fileHash' => hash('sha256', 'test content')]);

        $this->expectException(ApiError::class);
        $this->expectExceptionMessage('fileHash requires an uploaded file');

        $this->blobService->setUpFileDataFromRequest(new FileData(), $request);
    }

    public function testOutputValidationRejectsFileHashMismatch(): void
    {
        $fileData = $this->addTestFile();
        $fileData->setFileHash(hash('sha256', 'different file contents'));

        $this->expectException(ApiError::class);
        $this->expectExceptionMessage('sha256 file hash doesnt match!');

        $this->blobService->getFileData($fileData->getIdentifier());
    }

    public function testOutputValidationRejectsMetadataHashMismatch(): void
    {
        $fileData = $this->addTestFile();
        $fileData->setMetadata(self::TEST_METADATA_2);

        $this->expectException(ApiError::class);
        $this->expectExceptionMessage('sha256 metadata hash doesnt match!');

        $this->blobService->getFileData($fileData->getIdentifier());
    }

    public function testDisabledOutputValidationSkipsHashChecks(): void
    {
        $this->testConfig = BlobTestUtils::getTestConfig();
        $this->testConfig['buckets'][0]['output_validation'] = false;
        $this->setUpBlobService();
        $fileData = $this->addTestFile();
        $fileData->setFileHash(hash('sha256', 'different file contents'));
        $fileData->setMetadata(self::TEST_METADATA_2);

        $this->assertSame($fileData, $this->blobService->getFileData($fileData->getIdentifier()));
    }

    public function testOutputValidationRejectsMissingFileHash(): void
    {
        $fileData = $this->addTestFile();
        $fileData->setFileHash(null);

        $this->expectException(ApiError::class);
        $this->expectExceptionMessage('sha256 file hash is missing!');

        $this->blobService->getFileData($fileData->getIdentifier());
    }

    public function testOutputValidationRejectsMissingMetadataHash(): void
    {
        $fileData = $this->addTestFile();
        $fileData->setMetadataHash(null);

        $this->expectException(ApiError::class);
        $this->expectExceptionMessage('sha256 metadata hash is missing!');

        $this->blobService->getFileData($fileData->getIdentifier());
    }

    public function testOutputValidationAllowsAbsentMetadata(): void
    {
        $fileData = $this->addTestFile();
        $fileData->setMetadata(null);
        $fileData->setMetadataHash(null);

        $this->assertSame($fileData, $this->blobService->getFileData($fileData->getIdentifier()));
    }

    public function testDisabledOutputValidationAllowsMissingHashes(): void
    {
        $this->testConfig = BlobTestUtils::getTestConfig();
        $this->testConfig['buckets'][0]['output_validation'] = false;
        $this->setUpBlobService();
        $fileData = $this->addTestFile();
        $fileData->setFileHash(null);
        $fileData->setMetadataHash(null);

        $this->assertSame($fileData, $this->blobService->getFileData($fileData->getIdentifier()));
    }

    public function testMetadataHashWithoutMetadataIsRejected(): void
    {
        $request = Request::create('/blob/files?bucketIdentifier=test-bucket', 'PATCH',
            ['metadataHash' => hash('sha256', '{"foo":"bar"}')]);

        $this->expectException(ApiError::class);
        $this->expectExceptionMessage('metadataHash requires metadata');

        $this->blobService->setUpFileDataFromRequest(new FileData(), $request);
    }

    public function testMetadataHashMismatchHasClearMessage(): void
    {
        $request = Request::create('/blob/files?bucketIdentifier=test-bucket', 'PATCH',
            ['metadata' => self::TEST_METADATA, 'metadataHash' => hash('sha256', self::TEST_METADATA_2)]);

        $this->expectException(ApiError::class);
        $this->expectExceptionMessage('Metadata hash does not match the submitted metadata');

        $this->blobService->setUpFileDataFromRequest(new FileData(), $request);
    }

    public function testInvalidRetentionDurationIsBadRequest(): void
    {
        $request = Request::create('/blob/files?bucketIdentifier=test-bucket&deleteIn=invalid', 'PATCH');
        try {
            $this->blobService->setUpFileDataFromRequest(new FileData(), $request);
            $this->fail('Expected an invalid duration error');
        } catch (ApiError $error) {
            $this->assertSame(400, $error->getStatusCode());
            $this->assertNull($error->getErrorId());
            $this->assertStringContainsString('deleteIn', $error->getMessage());
        }
    }

    public function testInvalidPatchMetadataIsBadRequest(): void
    {
        $fileData = $this->addTestFile();
        $previousFileData = clone $fileData;
        $fileData->setFile(null);
        $fileData->setMetadata('[1,2,3]');
        try {
            $this->blobService->updateFile($fileData, $previousFileData);
            $this->fail('Expected invalid patch metadata to be rejected');
        } catch (ApiError $error) {
            $this->assertSame(400, $error->getStatusCode());
            $this->assertSame('blob:metadata-invalid', $error->getErrorId());
            $this->assertSame('Metadata must be a JSON object', $error->getMessage());
        }
    }

    /**
     * Only JSON objects are allowed as top level metadata value.
     */
    public function testAddFileWithNonObjectMetadataIsRejected(): void
    {
        $invalidMetadataValues = [
            '[1,2,3]',      // array
            '"a string"',   // string
            '42',           // number
            'true',         // boolean
            'null',         // null
        ];

        foreach ($invalidMetadataValues as $metadata) {
            try {
                $this->addTestFile(metadata: $metadata);
                $this->fail('Expected ApiError was not thrown for metadata: '.$metadata);
            } catch (ApiError $apiError) {
                $this->assertSame('blob:metadata-invalid', $apiError->getErrorId());
                $this->assertSame(400, $apiError->getStatusCode());
                $this->assertStringContainsString('Metadata must be', $apiError->getMessage());
            }
        }
    }

    /**
     * @throws \Exception
     */
    public function testAddFileWithInvalidJsonMetadataIsRejected(): void
    {
        try {
            $this->addTestFile(metadata: '{not valid json');
            $this->fail('Expected ApiError was not thrown');
        } catch (ApiError $apiError) {
            $this->assertSame('blob:metadata-invalid', $apiError->getErrorId());
            $this->assertSame(400, $apiError->getStatusCode());
            $this->assertSame('Metadata must be valid JSON', $apiError->getMessage());
        }
    }

    public function testStoredMetadataValidationIsServerError(): void
    {
        $this->testConfig = BlobTestUtils::getTestConfig();
        $this->testConfig['buckets'][0]['types']['document'] = [
            'json_schema_path' => __DIR__.'/Fixtures/schema-validation/demo-document-draft7.schema.json',
            'verity_profile' => null,
        ];
        $this->setUpBlobService();
        $fileData = $this->addTestFile();
        $fileData->setType('document');

        try {
            $this->blobService->getFileData($fileData->getIdentifier());
            $this->fail('Expected invalid stored metadata to be rejected');
        } catch (ApiError $error) {
            $this->assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $error->getStatusCode());
            $this->assertSame('blob:metadata-validation-failed', $error->getErrorId());
            $this->assertNotEmpty($error->getErrorDetails());
        }
    }

    public function testUnknownStoredTypeIsServerError(): void
    {
        $fileData = $this->addTestFile();
        $fileData->setType('unknown');

        try {
            $this->blobService->getFileData($fileData->getIdentifier());
            $this->fail('Expected unknown stored type to be rejected');
        } catch (ApiError $error) {
            $this->assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $error->getStatusCode());
            $this->assertSame('blob:type-unknown', $error->getErrorId());
        }
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

    /**
     * @throws \Exception
     */
    public function testGetFileCount(): void
    {
        $testBucketConfig = self::getTestBucketConfig();
        $internalBucketId = $testBucketConfig['internal_bucket_id'];

        $this->assertEquals(0, $this->blobService->getFileCountByInternalBucketId($internalBucketId));

        $this->addTestFile();
        $this->assertEquals(1, $this->blobService->getFileCountByInternalBucketId($internalBucketId));

        $this->addTestFile();
        $this->assertEquals(2, $this->blobService->getFileCountByInternalBucketId($internalBucketId));

        $fileData = $this->addTestFile();
        $this->assertEquals(3, $this->blobService->getFileCountByInternalBucketId($internalBucketId));

        $this->blobService->removeFile($fileData);
        $this->assertEquals(2, $this->blobService->getFileCountByInternalBucketId($internalBucketId));
    }

    protected static function getTestBucketConfig(int $index = 0): ?array
    {
        return BlobTestUtils::getTestConfig()['buckets'][$index] ?? null;
    }
}
