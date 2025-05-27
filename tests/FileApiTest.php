<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Tests;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use Dbp\Relay\BlobBundle\Api\FileApi;
use Dbp\Relay\BlobBundle\TestUtils\BlobTestUtils;
use Dbp\Relay\BlobBundle\TestUtils\TestEntityManager;
use Dbp\Relay\BlobLibrary\Api\BlobApi;
use Dbp\Relay\BlobLibrary\Api\BlobApiError;
use Dbp\Relay\BlobLibrary\Api\BlobFile;
use Dbp\Relay\CoreBundle\Rest\Query\Filter\FilterException;
use Dbp\Relay\CoreBundle\Rest\Query\Filter\FilterTreeBuilder;
use GuzzleHttp\Psr7\Utils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

class FileApiTest extends ApiTestCase
{
    private const TEST_BUCKET_IDENTIFIER = 'test-bucket';
    private const TEST_PREFIX = 'prefix';
    private const TEST_FILENAME = 'test.txt';
    private const TEST_FILE_CONTENTS = 'this is a test file content';

    private ?FileApi $fileApi = null;
    private ?RequestStack $requestStack = null;
    private TestEntityManager $testEntityManager;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $this->testEntityManager = BlobTestUtils::createTestEntityManager($kernel->getContainer());

        $this->requestStack = new RequestStack();
        $this->requestStack->push(Request::create('https://example.com/blob/files'));
        $this->fileApi = new FileApi(
            BlobTestUtils::createTestBlobService($this->testEntityManager->getEntityManager()), $this->requestStack);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        BlobTestUtils::tearDown();
    }

    /**
     * @throws BlobApiError
     */
    public function testWithoutRequest(): void
    {
        // without a current request, the content url should be without http scheme and host
        $this->requestStack->pop();
        $blobFile = $this->addTestFile();
        $this->assertStringStartsWith('/blob/files/'.$blobFile->getIdentifier(), $blobFile->getContentUrl());
    }

    /**
     * @throws BlobApiError
     */
    public function testCreateLocalModeBlobApi(): void
    {
        $blobApi = BlobTestUtils::createLocalModeBlobApi('test-bucket', $this->testEntityManager->getEntityManager());

        $blobFile = new BlobFile();
        $blobFile->setPrefix('prefix');
        $blobFile->setFileName(self::TEST_FILENAME);
        $blobFile->setFile('example content');

        $blobFile = $blobApi->addFile($blobFile);
        $blobFileFromGet = $blobApi->getFile($blobFile->getIdentifier());
        $this->assertEquals($blobFile->getIdentifier(), $blobFileFromGet->getIdentifier());
    }

    /**
     * @throws BlobApiError
     */
    public function testAddFileString(): void
    {
        $blobFile = new BlobFile();
        $blobFile->setPrefix('prefix');
        $blobFile->setFileName(self::TEST_FILENAME);
        $blobFile->setFile('data');

        $blobFile = $this->fileApi->addFile(self::TEST_BUCKET_IDENTIFIER, $blobFile);
        $this->assertTrue(Uuid::isValid($blobFile->getIdentifier()));
        $this->assertFileIsFound($blobFile->getIdentifier());
        $this->assertFileContentsEquals($blobFile->getIdentifier(), 'data');
        $this->assertStringStartsWith('https://example.com/blob/files/'.$blobFile->getIdentifier(), $blobFile->getContentUrl());
    }

    /**
     * @throws BlobApiError
     */
    public function testAddFileSplFileInfoSuccess(): void
    {
        $blobFile = new BlobFile();
        $blobFile->setPrefix('prefix');
        $blobFile->setFileName(self::TEST_FILENAME);
        $blobFile->setFile(new \SplFileInfo(__DIR__.'/test.txt'));

        $blobFile = $this->fileApi->addFile(self::TEST_BUCKET_IDENTIFIER, $blobFile);
        $this->assertTrue(Uuid::isValid($blobFile->getIdentifier()));
        $this->assertFileIsFound($blobFile->getIdentifier());
        $this->assertFileContentsEquals($blobFile->getIdentifier(), file_get_contents(__DIR__.'/test.txt'));
    }

    /**
     * @throws BlobApiError
     */
    public function testAddFileStreamInterfaceSuccess(): void
    {
        $blobFile = new BlobFile();
        $blobFile->setPrefix('prefix');
        $blobFile->setFileName(self::TEST_FILENAME);
        $blobFile->setFile(Utils::streamFor(fopen(__DIR__.'/test.txt', 'r')));

        $blobFile = $this->fileApi->addFile(self::TEST_BUCKET_IDENTIFIER, $blobFile);
        $this->assertTrue(Uuid::isValid($blobFile->getIdentifier()));
        $this->assertFileIsFound($blobFile->getIdentifier());
        $this->assertFileContentsEquals($blobFile->getIdentifier(), file_get_contents(__DIR__.'/test.txt'));
    }

    public function testAddFileError(): void
    {
        $blobFile = new BlobFile();
        $blobFile->setPrefix('prefix');
        $blobFile->setFileName(self::TEST_FILENAME);

        try {
            // the file is missing
            $this->fileApi->addFile(self::TEST_BUCKET_IDENTIFIER, $blobFile);
        } catch (BlobApiError $blobApiError) {
            $this->assertEquals(BlobApiError::CLIENT_ERROR, $blobApiError->getErrorId());
            $this->assertEquals(Response::HTTP_BAD_REQUEST, $blobApiError->getStatusCode());
        }
    }

    /**
     * @throws BlobApiError
     */
    public function testUpdateFile(): void
    {
        $blobFile = $this->addTestFile();
        $blobFile->setPrefix('new_prefix');
        $blobFile->setFileName('new_test.txt');
        $blobFile->setFile('new content');

        $blobFile = $this->fileApi->updateFile(self::TEST_BUCKET_IDENTIFIER, $blobFile);
        $blobFile = $this->fileApi->getFile(self::TEST_BUCKET_IDENTIFIER, $blobFile->getIdentifier());
        $this->assertEquals('new_test.txt', $blobFile->getFileName());
        $this->assertFileContentsEquals($blobFile->getIdentifier(), 'new content');
        $this->assertEquals('new_prefix', $blobFile->getPrefix());
        $this->assertStringStartsWith('https://example.com/blob/files/'.$blobFile->getIdentifier(), $blobFile->getContentUrl());
    }

    /**
     * @throws BlobApiError
     */
    public function testRemoveFile(): void
    {
        $blobFile = $this->addTestFile();
        $this->assertFileIsFound($blobFile->getIdentifier());
        $this->fileApi->removeFile(self::TEST_BUCKET_IDENTIFIER, $blobFile->getIdentifier());
        try {
            $this->fileApi->getFile(self::TEST_BUCKET_IDENTIFIER, $blobFile->getIdentifier());
        } catch (BlobApiError $blobApiError) {
            $this->assertEquals(BlobApiError::FILE_NOT_FOUND, $blobApiError->getErrorId());
            $this->assertEquals(Response::HTTP_NOT_FOUND, $blobApiError->getStatusCode());
        }
    }

    /**
     * @throws BlobApiError
     */
    public function testGetFile(): void
    {
        $blobFile = $this->addTestFile();
        $blobFileFromGet = $this->fileApi->getFile(self::TEST_BUCKET_IDENTIFIER, $blobFile->getIdentifier());
        $this->assertEquals($blobFile->getIdentifier(), $blobFileFromGet->getIdentifier());
        $this->assertStringStartsWith('https://example.com/blob/files/'.$blobFile->getIdentifier(), $blobFile->getContentUrl());
    }

    /**
     * @throws BlobApiError
     */
    public function testGetFiles(): void
    {
        $blobFile1 = $this->addTestFile();
        $blobFile2 = $this->addTestFile();
        $blobFile3 = $this->addTestFile();

        $blobFiles = $this->fileApi->getFiles(self::TEST_BUCKET_IDENTIFIER);
        $this->assertCount(3, $blobFiles);
        $this->assertInstanceOf(BlobFile::class, $blobFiles[0]);
        $this->assertEquals($blobFile1->getIdentifier(), $blobFiles[0]->getIdentifier());
        $this->assertStringStartsWith('https://example.com/blob/files/'.$blobFile1->getIdentifier(), $blobFiles[0]->getContentUrl());
        $this->assertEquals($blobFile2->getIdentifier(), $blobFiles[1]->getIdentifier());
        $this->assertInstanceOf(BlobFile::class, $blobFiles[1]);
        $this->assertStringStartsWith('https://example.com/blob/files/'.$blobFile2->getIdentifier(), $blobFiles[1]->getContentUrl());
        $this->assertEquals($blobFile3->getIdentifier(), $blobFiles[2]->getIdentifier());
        $this->assertInstanceOf(BlobFile::class, $blobFiles[2]);
        $this->assertStringStartsWith('https://example.com/blob/files/'.$blobFile3->getIdentifier(), $blobFiles[2]->getContentUrl());
    }

    /**
     * @throws BlobApiError
     */
    public function testGetFilesPagination(): void
    {
        $blobFile1 = $this->addTestFile();
        $blobFile2 = $this->addTestFile();
        $blobFile3 = $this->addTestFile();

        $blobFilePage1 = $this->fileApi->getFiles(self::TEST_BUCKET_IDENTIFIER, 1, 2);
        $this->assertCount(2, $blobFilePage1);
        $blobFilePage2 = $this->fileApi->getFiles(self::TEST_BUCKET_IDENTIFIER, 2, 2);
        $this->assertCount(1, $blobFilePage2);
        $blobFiles = array_merge($blobFilePage1, $blobFilePage2);

        $this->assertEquals($blobFile1->getIdentifier(), $blobFiles[0]->getIdentifier());
        $this->assertStringStartsWith('https://example.com/blob/files/'.$blobFile1->getIdentifier(), $blobFile1->getContentUrl());
        $this->assertEquals($blobFile2->getIdentifier(), $blobFiles[1]->getIdentifier());
        $this->assertStringStartsWith('https://example.com/blob/files/'.$blobFile2->getIdentifier(), $blobFile2->getContentUrl());
        $this->assertEquals($blobFile3->getIdentifier(), $blobFiles[2]->getIdentifier());
        $this->assertStringStartsWith('https://example.com/blob/files/'.$blobFile3->getIdentifier(), $blobFile3->getContentUrl());
    }

    /**
     * @throws BlobApiError
     */
    public function testGetFilesByPrefixDeprecated(): void
    {
        $blobFile1 = $this->addTestFile('prefix');
        $blobFile2 = $this->addTestFile('another_prefix');
        $blobFile3 = $this->addTestFile('prefix');

        $blobFiles = $this->fileApi->getFiles(self::TEST_BUCKET_IDENTIFIER, options: [BlobApi::PREFIX_OPTION => 'prefix']);
        $this->assertCount(2, $blobFiles);
        $this->assertEquals($blobFile1->getIdentifier(), $blobFiles[0]->getIdentifier());
        $this->assertEquals($blobFile3->getIdentifier(), $blobFiles[1]->getIdentifier());

        $blobFiles = $this->fileApi->getFiles(self::TEST_BUCKET_IDENTIFIER, options: [
            BlobApi::PREFIX_OPTION => 'another',
            BlobApi::PREFIX_STARTS_WITH_OPTION => false,
        ]);
        $this->assertCount(0, $blobFiles);

        $blobFiles = $this->fileApi->getFiles(self::TEST_BUCKET_IDENTIFIER, options: [
            BlobApi::PREFIX_OPTION => 'another',
            BlobApi::PREFIX_STARTS_WITH_OPTION => true,
        ]);
        $this->assertCount(1, $blobFiles);
        $this->assertEquals($blobFile2->getIdentifier(), $blobFiles[0]->getIdentifier());
    }

    /**
     * @throws BlobApiError
     * @throws FilterException
     */
    public function testGetFilesWithFilterOption(): void
    {
        $blobFile1 = $this->addTestFile('prefix', 'test2.txt');
        $blobFile2 = $this->addTestFile('another_prefix');
        $blobFile3 = $this->addTestFile('prefix');

        $filter = FilterTreeBuilder::create()
            ->iEndsWith('prefix', 'prefix')
            ->createFilter();

        $blobFiles = $this->fileApi->getFiles(self::TEST_BUCKET_IDENTIFIER, options: ['filter' => $filter]);
        $this->assertCount(3, $blobFiles);
        $this->assertEquals($blobFile1->getIdentifier(), $blobFiles[0]->getIdentifier());
        $this->assertEquals($blobFile2->getIdentifier(), $blobFiles[1]->getIdentifier());
        $this->assertEquals($blobFile3->getIdentifier(), $blobFiles[2]->getIdentifier());

        $filter = FilterTreeBuilder::create()
            ->equals('fileName', 'test2.txt')
            ->createFilter();

        $blobFiles = $this->fileApi->getFiles(self::TEST_BUCKET_IDENTIFIER, options: ['filter' => $filter]);
        $this->assertCount(1, $blobFiles);
        $this->assertEquals($blobFile1->getIdentifier(), $blobFiles[0]->getIdentifier());
    }

    /**
     * @throws BlobApiError
     */
    public function testGetFileStream(): void
    {
        $blobFile = $this->addTestFile();
        $blobFileStream =
            $this->fileApi->getFileStream(self::TEST_BUCKET_IDENTIFIER, $blobFile->getIdentifier());
        $this->assertEquals(self::TEST_FILE_CONTENTS, $blobFileStream->getFileStream()->getContents());
        $this->assertEquals(self::TEST_FILENAME, $blobFileStream->getFileName());
        $this->assertEquals('text/plain', $blobFileStream->getMimeType());
        $this->assertEquals(strlen(self::TEST_FILE_CONTENTS), $blobFileStream->getFileSize());
    }

    /**
     * @throws BlobApiError
     */
    public function testDeleteFilesByPrefixDeprecated(): void
    {
        $this->addTestFile('prefix');
        $blobFile2 = $this->addTestFile('another_prefix');
        $this->addTestFile('prefix');

        $this->fileApi->removeFiles(self::TEST_BUCKET_IDENTIFIER, options: [BlobApi::PREFIX_OPTION => 'prefix']);
        $blobFiles = $this->fileApi->getFiles(self::TEST_BUCKET_IDENTIFIER);
        $this->assertCount(1, $blobFiles);
        $this->assertEquals($blobFile2->getIdentifier(), $blobFiles[0]->getIdentifier());

        $this->fileApi->removeFiles(self::TEST_BUCKET_IDENTIFIER, options: [
            BlobApi::PREFIX_OPTION => 'another',
            BlobApi::PREFIX_STARTS_WITH_OPTION => true,
        ]);
        $blobFiles = $this->fileApi->getFiles(self::TEST_BUCKET_IDENTIFIER);
        $this->assertCount(0, $blobFiles);
    }

    /**
     * @throws FilterException
     * @throws BlobApiError
     */
    public function testDeleteFilesWithFilter(): void
    {
        $blobFile1 = $this->addTestFile('prefix');
        $blobFile2 = $this->addTestFile('another_foo_prefix');
        $blobFile3 = $this->addTestFile('foo');
        $blobFile3 = $this->addTestFile('more_foo');

        $filter = FilterTreeBuilder::create()
            ->iContains('prefix', 'foo')
            ->createFilter();

        $this->fileApi->removeFiles(self::TEST_BUCKET_IDENTIFIER, options: ['filter' => $filter]);
        $blobFiles = $this->fileApi->getFiles(self::TEST_BUCKET_IDENTIFIER);
        $this->assertCount(1, $blobFiles);
        $this->assertEquals($blobFile1->getIdentifier(), $blobFiles[0]->getIdentifier());
    }

    /**
     * @throws BlobApiError
     */
    private function addTestFile(?string $prefix = self::TEST_PREFIX, ?string $fileName = self::TEST_FILENAME,
        ?string $fileContent = self::TEST_FILE_CONTENTS): BlobFile
    {
        $blobFile = new BlobFile();
        $blobFile->setPrefix($prefix);
        $blobFile->setFileName($fileName);
        $blobFile->setFile($fileContent);

        return $this->fileApi->addFile(self::TEST_BUCKET_IDENTIFIER, $blobFile);
    }

    /**
     * @throws BlobApiError
     */
    private function assertFileIsFound(string $identifier): void
    {
        $this->assertEquals($identifier, $this->fileApi->getFile(self::TEST_BUCKET_IDENTIFIER, $identifier)->getIdentifier());
    }

    /**
     * @throws BlobApiError
     */
    private function assertFileContentsEquals(string $identifier, string $expectedContent): void
    {
        $this->assertEquals($expectedContent,
            $this->fileApi->getFileStream(self::TEST_BUCKET_IDENTIFIER, $identifier)->getFileStream()->getContents());
    }
}
