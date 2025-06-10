<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Tests;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use Dbp\Relay\BlobBundle\Api\FileApi;
use Dbp\Relay\BlobBundle\Service\BlobService;
use Dbp\Relay\BlobBundle\TestUtils\BlobTestUtils;
use Dbp\Relay\BlobBundle\TestUtils\TestEntityManager;
use Dbp\Relay\BlobLibrary\Api\BlobApi;
use Dbp\Relay\BlobLibrary\Api\BlobApiError;
use Dbp\Relay\BlobLibrary\Api\BlobFile;
use Dbp\Relay\BlobLibrary\Helpers\TestUtils;
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
    private const BLOB_BASE_URL = 'https://blob.com';

    private ?FileApi $fileApi = null;
    private ?RequestStack $requestStack = null;
    private ?TestEntityManager $testEntityManager = null;
    private ?BlobService $blobService = null;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $this->testEntityManager = BlobTestUtils::createTestEntityManager($kernel->getContainer());

        $this->requestStack = new RequestStack();
        $this->requestStack->push(Request::create(self::BLOB_BASE_URL.'/blob/files'));
        $this->blobService = BlobTestUtils::createTestBlobService($this->testEntityManager->getEntityManager());
        $this->fileApi = new FileApi($this->blobService, $this->requestStack);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        BlobTestUtils::tearDown();
    }

    /**
     * @throws BlobApiError
     */
    public function testAddFileWithoutRequest(): void
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
        $this->assertStringStartsWith(self::BLOB_BASE_URL.'/blob/files/'.$blobFile->getIdentifier(), $blobFile->getContentUrl());
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

        $updatedBlobFile = new BlobFile();
        $updatedBlobFile->setIdentifier($blobFile->getIdentifier());
        $updatedBlobFile->setPrefix('new_prefix');
        $updatedBlobFile->setFileName('new_test.txt');
        $updatedBlobFile->setFile('new content');

        $updatedBlobFile = $this->fileApi->updateFile(self::TEST_BUCKET_IDENTIFIER, $updatedBlobFile);
        $this->assertEquals('new_test.txt', $updatedBlobFile->getFileName());
        $this->assertFileContentsEquals($updatedBlobFile->getIdentifier(), 'new content');
        $this->assertEquals('new_prefix', $updatedBlobFile->getPrefix());
        $this->assertStringStartsWith(self::BLOB_BASE_URL.'/blob/files/'.$updatedBlobFile->getIdentifier(), $updatedBlobFile->getContentUrl());

        $updatedBlobFile = $this->fileApi->getFile(self::TEST_BUCKET_IDENTIFIER, $updatedBlobFile->getIdentifier());
        $this->assertEquals('new_test.txt', $updatedBlobFile->getFileName());
        $this->assertFileContentsEquals($updatedBlobFile->getIdentifier(), 'new content');
        $this->assertEquals('new_prefix', $updatedBlobFile->getPrefix());
        $this->assertStringStartsWith(self::BLOB_BASE_URL.'/blob/files/'.$updatedBlobFile->getIdentifier(), $updatedBlobFile->getContentUrl());
    }

    public function testUpdateFileNotFound(): void
    {
        $updatedBlobFile = new BlobFile();
        $updatedBlobFile->setIdentifier('019746a0-dedf-767e-b8f6-c5aaa9dc9f7c');
        $updatedBlobFile->setPrefix('new_prefix');

        try {
            $this->fileApi->updateFile(self::TEST_BUCKET_IDENTIFIER, $updatedBlobFile);
            $this->fail('Expected BlobApiError');
        } catch (BlobApiError $blobApiError) {
            $this->assertEquals(BlobApiError::FILE_NOT_FOUND, $blobApiError->getErrorId());
        }
    }

    /**
     * @throws BlobApiError
     */
    public function testUpdateFileWithIncludeDeleteAtOption(): void
    {
        $blobFileIdentifier = $this->addTestFile(options: [BlobApi::DELETE_IN_OPTION => 'PT3S'])->getIdentifier();

        $updatedBlobFile = new BlobFile();
        $updatedBlobFile->setIdentifier($blobFileIdentifier);
        $updatedBlobFile->setPrefix('new_prefix');

        try {
            $this->fileApi->updateFile(self::TEST_BUCKET_IDENTIFIER, $updatedBlobFile);
            $this->fail('Expected BlobApiError');
        } catch (BlobApiError $blobApiError) {
            $this->assertEquals(BlobApiError::FILE_NOT_FOUND, $blobApiError->getErrorId());
        }

        $this->fileApi->updateFile(self::TEST_BUCKET_IDENTIFIER, $updatedBlobFile,
            options: [BlobApi::INCLUDE_DELETE_AT_OPTION => true]);
    }

    /**
     * @throws BlobApiError
     */
    public function testUpdateFileWithIncludeFileContentsOption(): void
    {
        $blobFileIdentifier = $this->addTestFile()->getIdentifier();

        $updatedBlobFile = new BlobFile();
        $updatedBlobFile->setIdentifier($blobFileIdentifier);
        $updatedBlobFile->setPrefix('new_prefix');

        $blobFile = $this->fileApi->updateFile(self::TEST_BUCKET_IDENTIFIER, $updatedBlobFile,
            options: [BlobApi::INCLUDE_FILE_CONTENTS_OPTION => true]);
        $this->assertEquals('data:text/plain;base64,'.base64_encode(self::TEST_FILE_CONTENTS), $blobFile->getContentUrl());
    }

    /**
     * @throws BlobApiError
     */
    public function testRemoveFile(): void
    {
        $blobFileIdentifier = $this->addTestFile()->getIdentifier();
        $this->assertFileIsFound($blobFileIdentifier);
        $this->fileApi->removeFile(self::TEST_BUCKET_IDENTIFIER, $blobFileIdentifier);
        try {
            $this->fileApi->getFile(self::TEST_BUCKET_IDENTIFIER, $blobFileIdentifier);
        } catch (BlobApiError $blobApiError) {
            $this->assertEquals(BlobApiError::FILE_NOT_FOUND, $blobApiError->getErrorId());
            $this->assertEquals(Response::HTTP_NOT_FOUND, $blobApiError->getStatusCode());
        }
    }

    public function testRemoveFileNotFound(): void
    {
        try {
            $this->fileApi->removeFile(self::TEST_BUCKET_IDENTIFIER, '019746a0-dedf-767e-b8f6-c5aaa9dc9f7c');
            $this->fail('Expected BlobApiError');
        } catch (BlobApiError $blobApiError) {
            $this->assertEquals(BlobApiError::FILE_NOT_FOUND, $blobApiError->getErrorId());
        }
    }

    /**
     * @throws BlobApiError
     */
    public function testRemoveFileWithIncludeDeleteAtOption(): void
    {
        $blobFileIdentifier = $this->addTestFile(options: [BlobApi::DELETE_IN_OPTION => 'PT3S'])->getIdentifier();

        try {
            $this->fileApi->removeFile(self::TEST_BUCKET_IDENTIFIER, $blobFileIdentifier);
            $this->fail('Expected BlobApiError');
        } catch (BlobApiError $blobApiError) {
            $this->assertEquals(BlobApiError::FILE_NOT_FOUND, $blobApiError->getErrorId());
        }

        $this->fileApi->removeFile(self::TEST_BUCKET_IDENTIFIER, $blobFileIdentifier,
            options: [BlobApi::INCLUDE_DELETE_AT_OPTION => true]);
        try {
            $this->fileApi->getFile(self::TEST_BUCKET_IDENTIFIER, $blobFileIdentifier);
            $this->fail('Expected 404');
        } catch (BlobApiError $blobApiError) {
            $this->assertEquals(BlobApiError::FILE_NOT_FOUND, $blobApiError->getErrorId());
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
        $this->assertStringStartsWith(self::BLOB_BASE_URL.'/blob/files/'.$blobFile->getIdentifier(), $blobFile->getContentUrl());
    }

    /**
     * @throws BlobApiError
     */
    public function testGetFileNotFound(): void
    {
        try {
            $this->fileApi->getFile(self::TEST_BUCKET_IDENTIFIER, '019746a0-dedf-767e-b8f6-c5aaa9dc9f7c');
        } catch (BlobApiError $blobApiError) {
            $this->assertEquals(BlobApiError::FILE_NOT_FOUND, $blobApiError->getErrorId());
            $this->assertEquals(Response::HTTP_NOT_FOUND, $blobApiError->getStatusCode());
        }
    }

    /**
     * @throws BlobApiError
     */
    public function testGetFileWithIncludeDeleteAtOption(): void
    {
        $blobFileIdentifier = $this->addTestFile(options: [BlobApi::DELETE_IN_OPTION => 'PT3S'])->getIdentifier();

        try {
            $this->fileApi->getFile(self::TEST_BUCKET_IDENTIFIER, $blobFileIdentifier);
            $this->fail('Expected BlobApiError');
        } catch (BlobApiError $blobApiError) {
            $this->assertEquals(BlobApiError::FILE_NOT_FOUND, $blobApiError->getErrorId());
            $this->assertEquals(Response::HTTP_NOT_FOUND, $blobApiError->getStatusCode());
        }

        $this->assertEquals($blobFileIdentifier,
            $this->fileApi->getFile(self::TEST_BUCKET_IDENTIFIER, $blobFileIdentifier,
                options: [BlobApi::INCLUDE_DELETE_AT_OPTION => true])->getIdentifier());
    }

    /**
     * @throws BlobApiError
     */
    public function testGetFileWithIncludeFileContentsOption(): void
    {
        $blobFileIdentifier = $this->addTestFile()->getIdentifier();
        $this->assertEquals('data:text/plain;base64,'.base64_encode(self::TEST_FILE_CONTENTS),
            $this->fileApi->getFile(self::TEST_BUCKET_IDENTIFIER, $blobFileIdentifier,
                options: [BlobApi::INCLUDE_FILE_CONTENTS_OPTION => true])->getContentUrl());
    }

    /**
     * @throws BlobApiError
     */
    public function testGetFileWithDisableOutputValidationOption(): void
    {
        $blobFile = $this->addTestFile();
        // illegally modify file contents:
        $this->saveFile(self::TEST_BUCKET_IDENTIFIER, $blobFile->getIdentifier());

        try {
            $this->fileApi->getFile(self::TEST_BUCKET_IDENTIFIER, $blobFile->getIdentifier());
            $this->fail('Expected BlobApiError');
        } catch (BlobApiError $blobApiError) {
            $this->assertEquals(BlobApiError::CLIENT_ERROR, $blobApiError->getErrorId());
            $this->assertEquals(Response::HTTP_CONFLICT, $blobApiError->getStatusCode());
        }

        $this->fileApi->getFile(self::TEST_BUCKET_IDENTIFIER, $blobFile->getIdentifier(),
            options: [BlobApi::DISABLE_OUTPUT_VALIDATION_OPTION => true]);
    }

    /**
     * @throws BlobApiError
     */
    public function testGetFiles(): void
    {
        $blobFile1 = $this->addTestFile();
        $blobFile2 = $this->addTestFile();
        $blobFile3 = $this->addTestFile();

        $blobFiles = iterator_to_array($this->fileApi->getFiles(self::TEST_BUCKET_IDENTIFIER));
        $this->assertCount(3, $blobFiles);
        $this->assertInstanceOf(BlobFile::class, $blobFiles[0]);
        $this->assertEquals($blobFile1->getIdentifier(), $blobFiles[0]->getIdentifier());
        $this->assertStringStartsWith(self::BLOB_BASE_URL.'/blob/files/'.$blobFile1->getIdentifier(), $blobFiles[0]->getContentUrl());
        $this->assertEquals($blobFile2->getIdentifier(), $blobFiles[1]->getIdentifier());
        $this->assertInstanceOf(BlobFile::class, $blobFiles[1]);
        $this->assertStringStartsWith(self::BLOB_BASE_URL.'/blob/files/'.$blobFile2->getIdentifier(), $blobFiles[1]->getContentUrl());
        $this->assertEquals($blobFile3->getIdentifier(), $blobFiles[2]->getIdentifier());
        $this->assertInstanceOf(BlobFile::class, $blobFiles[2]);
        $this->assertStringStartsWith(self::BLOB_BASE_URL.'/blob/files/'.$blobFile3->getIdentifier(), $blobFiles[2]->getContentUrl());
    }

    /**
     * @throws BlobApiError
     */
    public function testGetFilesWithIncludeFileContentsOption(): void
    {
        $fileContent1 = 'foo';
        $fileContent2 = 'bar';
        $fileContent3 = 'baz';
        $blobFile1 = $this->addTestFile(fileContent: $fileContent1);
        $blobFile2 = $this->addTestFile(fileContent: $fileContent2);
        $blobFile3 = $this->addTestFile(fileContent: $fileContent3);

        $blobFiles = iterator_to_array($this->fileApi->getFiles(self::TEST_BUCKET_IDENTIFIER,
            options: [BlobApi::INCLUDE_FILE_CONTENTS_OPTION => true]));
        $this->assertCount(3, $blobFiles);
        $this->assertInstanceOf(BlobFile::class, $blobFiles[0]);
        $this->assertEquals($blobFile1->getIdentifier(), $blobFiles[0]->getIdentifier());
        $this->assertStringStartsWith('data:text/plain;base64,'.base64_encode($fileContent1), $blobFiles[0]->getContentUrl());
        $this->assertEquals($blobFile2->getIdentifier(), $blobFiles[1]->getIdentifier());
        $this->assertInstanceOf(BlobFile::class, $blobFiles[1]);
        $this->assertStringStartsWith('data:text/plain;base64,'.base64_encode($fileContent2), $blobFiles[1]->getContentUrl());
        $this->assertEquals($blobFile3->getIdentifier(), $blobFiles[2]->getIdentifier());
        $this->assertInstanceOf(BlobFile::class, $blobFiles[2]);
        $this->assertStringStartsWith('data:text/plain;base64,'.base64_encode($fileContent3), $blobFiles[2]->getContentUrl());
    }

    /**
     * @throws BlobApiError
     */
    public function testGetFilesWithIncludeDeleteAtOption(): void
    {
        $blobFile1 = $this->addTestFile();
        $blobFile2 = $this->addTestFile(options: [BlobApi::DELETE_IN_OPTION => 'PT3S']);
        $blobFile3 = $this->addTestFile();

        $blobFiles = iterator_to_array($this->fileApi->getFiles(self::TEST_BUCKET_IDENTIFIER));
        $this->assertCount(2, $blobFiles);
        $this->assertContainsEquals($blobFile1, $blobFiles);
        $this->assertContainsEquals($blobFile3, $blobFiles);

        $blobFiles = iterator_to_array($this->fileApi->getFiles(self::TEST_BUCKET_IDENTIFIER,
            options: [BlobApi::INCLUDE_DELETE_AT_OPTION => true]));
        $this->assertCount(3, $blobFiles);
        $this->assertContainsEquals($blobFile1, $blobFiles);
        $this->assertContainsEquals($blobFile2, $blobFiles);
        $this->assertContainsEquals($blobFile3, $blobFiles);
    }

    /**
     * @throws BlobApiError
     */
    public function testGetFilesPagination(): void
    {
        $blobFile1 = $this->addTestFile();
        $blobFile2 = $this->addTestFile();
        $blobFile3 = $this->addTestFile();

        $blobFilePage1 = iterator_to_array($this->fileApi->getFiles(
            self::TEST_BUCKET_IDENTIFIER, 1, 2));
        $this->assertCount(2, $blobFilePage1);
        $blobFilePage2 = iterator_to_array($this->fileApi->getFiles(
            self::TEST_BUCKET_IDENTIFIER, 2, 2));
        $this->assertCount(1, $blobFilePage2);
        $blobFiles = array_merge($blobFilePage1, $blobFilePage2);

        $this->assertContainsEquals($blobFile1, $blobFiles);
        $this->assertContainsEquals($blobFile2, $blobFiles);
        $this->assertContainsEquals($blobFile3, $blobFiles);
    }

    /**
     * @throws BlobApiError
     */
    public function testGetFilesByPrefixDeprecated(): void
    {
        $blobFile1 = $this->addTestFile('prefix');
        $blobFile2 = $this->addTestFile('another_prefix');
        $blobFile3 = $this->addTestFile('prefix');

        $blobFiles = iterator_to_array($this->fileApi->getFiles(
            self::TEST_BUCKET_IDENTIFIER, options: [BlobApi::PREFIX_OPTION => 'prefix']));
        $this->assertCount(2, $blobFiles);
        $this->assertEquals($blobFile1->getIdentifier(), $blobFiles[0]->getIdentifier());
        $this->assertEquals($blobFile3->getIdentifier(), $blobFiles[1]->getIdentifier());

        $blobFiles = iterator_to_array($this->fileApi->getFiles(self::TEST_BUCKET_IDENTIFIER, options: [
            BlobApi::PREFIX_OPTION => 'another',
            BlobApi::PREFIX_STARTS_WITH_OPTION => false,
        ]));
        $this->assertCount(0, $blobFiles);

        $blobFiles = iterator_to_array($this->fileApi->getFiles(self::TEST_BUCKET_IDENTIFIER, options: [
            BlobApi::PREFIX_OPTION => 'another',
            BlobApi::PREFIX_STARTS_WITH_OPTION => true,
        ]));
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

        $blobFiles = iterator_to_array(
            $this->fileApi->getFiles(self::TEST_BUCKET_IDENTIFIER, options: ['filter' => $filter]));
        $this->assertCount(3, $blobFiles);
        $this->assertEquals($blobFile1->getIdentifier(), $blobFiles[0]->getIdentifier());
        $this->assertEquals($blobFile2->getIdentifier(), $blobFiles[1]->getIdentifier());
        $this->assertEquals($blobFile3->getIdentifier(), $blobFiles[2]->getIdentifier());

        $filter = FilterTreeBuilder::create()
            ->equals('fileName', 'test2.txt')
            ->createFilter();

        $blobFiles = iterator_to_array(
            $this->fileApi->getFiles(self::TEST_BUCKET_IDENTIFIER, options: ['filter' => $filter]));
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
    public function testGetFileStreamWithIncludeDeleteAtOption(): void
    {
        $blobFileIdentifier = $this->addTestFile(options: [BlobApi::DELETE_IN_OPTION => 'PT3S'])->getIdentifier();

        try {
            $this->fileApi->getFileStream(self::TEST_BUCKET_IDENTIFIER, $blobFileIdentifier);
            $this->fail('Expected BlobApiError');
        } catch (BlobApiError $blobApiError) {
            $this->assertEquals(BlobApiError::FILE_NOT_FOUND, $blobApiError->getErrorId());
            $this->assertEquals(Response::HTTP_NOT_FOUND, $blobApiError->getStatusCode());
        }

        $this->fileApi->getFileStream(self::TEST_BUCKET_IDENTIFIER, $blobFileIdentifier,
            options: [BlobApi::INCLUDE_DELETE_AT_OPTION => true]);
    }

    /**
     * @throws BlobApiError
     * @throws \Exception
     */
    public function testCreateSignedUrl(): void
    {
        $method = 'POST';
        $parameters = [
            'prefix' => 'foo',
            'metadata' => '{}',
        ];
        $signedUrl = $this->fileApi->createSignedUrl(self::TEST_BUCKET_IDENTIFIER, $method, $parameters);
        $this->validateUrl($signedUrl, $method, extraQueryParameters: $parameters);

        $method = 'GET';
        $options = [
            BlobApi::PREFIX_OPTION => 'foo',
            BlobApi::INCLUDE_DELETE_AT_OPTION => true,
            BlobApi::INCLUDE_FILE_CONTENTS_OPTION => true,
        ];
        $signedUrl = $this->fileApi->createSignedUrl(self::TEST_BUCKET_IDENTIFIER, $method, options: $options);
        $this->validateUrl($signedUrl, $method, extraQueryParameters: [
            BlobApi::PREFIX_OPTION => 'foo',
            BlobApi::INCLUDE_DELETE_AT_OPTION => '1',
            BlobApi::INCLUDE_FILE_CONTENTS_OPTION => '1',
        ]);

        $identifier = '019746a0-dedf-767e-b8f6-c5aaa9dc9f7c';
        $options = [
            BlobApi::DISABLE_OUTPUT_VALIDATION_OPTION => true,
        ];
        $signedUrl = $this->fileApi->createSignedUrl(self::TEST_BUCKET_IDENTIFIER, $method,
            options: $options, identifier: $identifier);
        $this->validateUrl($signedUrl, $method, $identifier, extraQueryParameters: [
            BlobApi::DISABLE_OUTPUT_VALIDATION_OPTION => '1',
        ]);

        $options = [
            BlobApi::INCLUDE_DELETE_AT_OPTION => true,
        ];
        $action = 'download';
        $signedUrl = $this->fileApi->createSignedUrl(self::TEST_BUCKET_IDENTIFIER, $method,
            options: $options, identifier: $identifier, action: $action);
        $this->validateUrl($signedUrl, $method, $identifier, $action, extraQueryParameters: [
            BlobApi::INCLUDE_DELETE_AT_OPTION => '1',
        ]);

        $this->assertTrue(true);
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
        $blobFiles = iterator_to_array($this->fileApi->getFiles(self::TEST_BUCKET_IDENTIFIER));
        $this->assertCount(1, $blobFiles);
        $this->assertEquals($blobFile2->getIdentifier(), $blobFiles[0]->getIdentifier());

        $this->fileApi->removeFiles(self::TEST_BUCKET_IDENTIFIER, options: [
            BlobApi::PREFIX_OPTION => 'another',
            BlobApi::PREFIX_STARTS_WITH_OPTION => true,
        ]);
        $blobFiles = iterator_to_array($this->fileApi->getFiles(self::TEST_BUCKET_IDENTIFIER));
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
        $blobFiles = iterator_to_array($this->fileApi->getFiles(self::TEST_BUCKET_IDENTIFIER));
        $this->assertCount(1, $blobFiles);
        $this->assertEquals($blobFile1->getIdentifier(), $blobFiles[0]->getIdentifier());
    }

    /**
     * @throws BlobApiError
     */
    protected function addTestFile(?string $prefix = self::TEST_PREFIX, ?string $fileName = self::TEST_FILENAME,
        ?string $fileContent = self::TEST_FILE_CONTENTS, array $options = []): BlobFile
    {
        $blobFile = new BlobFile();
        $blobFile->setPrefix($prefix);
        $blobFile->setFileName($fileName);
        $blobFile->setFile($fileContent);

        $blobFile = $this->fileApi->addFile(self::TEST_BUCKET_IDENTIFIER, $blobFile, $options);
        // prevent the same FileData instance (with file attribute set) to be re-used by Doctrine on subsequent get/update:
        $this->testEntityManager->getEntityManager()->clear();

        return $blobFile;
    }

    /**
     * @throws \Exception
     */
    protected function validateUrl(string $url, string $method, ?string $identifier = null,
        ?string $action = null, array $extraQueryParameters = []): void
    {
        $bucketKey = $this->blobService->getBucketConfig(
            $this->blobService->getInternalBucketIdByBucketID(self::TEST_BUCKET_IDENTIFIER))->getKey();

        TestUtils::validateSignedUrl(self::TEST_BUCKET_IDENTIFIER, $bucketKey, self::BLOB_BASE_URL, $url,
            $method, $identifier, $action, $extraQueryParameters);
    }

    /**
     * @throws BlobApiError
     */
    protected function assertFileIsFound(string $identifier): void
    {
        $this->assertEquals($identifier, $this->fileApi->getFile(self::TEST_BUCKET_IDENTIFIER, $identifier)->getIdentifier());
    }

    /**
     * @throws BlobApiError
     */
    protected function assertFileContentsEquals(string $identifier, string $expectedContent): void
    {
        $this->assertEquals($expectedContent,
            $this->fileApi->getFileStream(self::TEST_BUCKET_IDENTIFIER, $identifier)->getFileStream()->getContents());
    }

    protected function saveFile(string $bucketIdentifier, string $fileIdentifier, string $filePath = __DIR__.'/test.txt'): void
    {
        $internalBucketIdentifier = $this->blobService->getInternalBucketIdByBucketID($bucketIdentifier);

        $this->blobService->getDatasystemProvider($internalBucketIdentifier)
            ->saveFile($internalBucketIdentifier, $fileIdentifier, new \SplFileInfo($filePath));
    }
}
