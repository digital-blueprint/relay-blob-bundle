<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Tests;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use Dbp\Relay\BlobBundle\Api\FileApi;
use Dbp\Relay\BlobBundle\Service\BlobService;
use Dbp\Relay\BlobBundle\TestUtils\BlobTestUtils;
use Dbp\Relay\BlobBundle\TestUtils\TestEntityManager;
use Dbp\Relay\BlobLibrary\Api\BlobApiError;
use Dbp\Relay\BlobLibrary\Api\BlobFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

class FileApiSchemaValidationTest extends ApiTestCase
{
    private const TEST_BUCKET_IDENTIFIER = 'test-bucket';
    private const TEST_FILENAME = 'test.txt';
    private const TEST_FILE_CONTENTS = 'this is a test file content';
    private const BLOB_BASE_URL = 'https://blob.com';
    private const DEMO_DOCUMENT_DRAFT4_TYPE = 'demoDocumentDraft4';
    private const DEMO_DOCUMENT_DRAFT6_TYPE = 'demoDocumentDraft6';
    private const DEMO_DOCUMENT_DRAFT7_TYPE = 'demoDocumentDraft7';
    private const DEMO_DOCUMENT_DRAFT2019_TYPE = 'demoDocumentDraft2019';

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

        $testConfig = BlobTestUtils::getTestConfig();
        $testConfig['buckets'][0]['types'] = [
            self::DEMO_DOCUMENT_DRAFT4_TYPE => [
                'json_schema_path' => __DIR__.'/Fixtures/schema-validation/demo-document-draft4.schema.json',
                'verity_profile' => null,
            ],
            self::DEMO_DOCUMENT_DRAFT6_TYPE => [
                'json_schema_path' => __DIR__.'/Fixtures/schema-validation/demo-document-draft6.schema.json',
                'verity_profile' => null,
            ],
            self::DEMO_DOCUMENT_DRAFT7_TYPE => [
                'json_schema_path' => __DIR__.'/Fixtures/schema-validation/demo-document-draft7.schema.json',
                'verity_profile' => null,
            ],
            self::DEMO_DOCUMENT_DRAFT2019_TYPE => [
                'json_schema_path' => __DIR__.'/Fixtures/schema-validation/demo-document-draft2019-09.schema.json',
                'verity_profile' => null,
            ],
        ];

        $this->blobService = BlobTestUtils::createTestBlobService($this->testEntityManager->getEntityManager(), $testConfig);
        $this->fileApi = new FileApi($this->blobService, $this->requestStack);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        BlobTestUtils::tearDown();
    }

    /**
     * @throws BlobApiError
     * @throws \JsonException
     */
    public function testAddFileWithDraft4SchemaValidatedMetadata(): void
    {
        $metadata = json_encode([
            '@type' => 'DemoDocument',
            'owner' => [
                'id' => 'person-123',
            ],
            'groupId' => 'aae64261-e417-4fa4-b1f5-5c5c7d0c3ba4',
            'status' => 'final',
        ], JSON_THROW_ON_ERROR);
        $blobFile = self::createDemoDocumentBlobFile(self::DEMO_DOCUMENT_DRAFT4_TYPE, $metadata);

        // Draft-04: resolves definitions from a referenced schema in a subdirectory.
        $blobFile = $this->fileApi->addFile(self::TEST_BUCKET_IDENTIFIER, $blobFile);

        $this->assertTrue(Uuid::isValid($blobFile->getIdentifier()));
        $this->assertEquals(self::DEMO_DOCUMENT_DRAFT4_TYPE, $blobFile->getType());
        $this->assertEquals($metadata, $blobFile->getMetadata());
        $this->assertEquals($blobFile->getIdentifier(), $this->fileApi->getFile(self::TEST_BUCKET_IDENTIFIER, $blobFile->getIdentifier())->getIdentifier());
    }

    public function testAddFileWithDraft4SchemaValidatedMetadataMissingPropertyFails(): void
    {
        $metadata = json_encode([
            '@type' => 'DemoDocument',
            'groupId' => 'aae64261-e417-4fa4-b1f5-5c5c7d0c3ba4',
            'status' => 'final',
        ], JSON_THROW_ON_ERROR);
        $blobFile = self::createDemoDocumentBlobFile(
            self::DEMO_DOCUMENT_DRAFT4_TYPE,
            $metadata
        );

        // Draft-04: reports missing required properties from referenced definitions.
        try {
            $this->fileApi->addFile(self::TEST_BUCKET_IDENTIFIER, $blobFile);
            $this->fail('Expected BlobApiError');
        } catch (BlobApiError $blobApiError) {
            $this->assertEquals(BlobApiError::CLIENT_ERROR, $blobApiError->getErrorId());
            $this->assertEquals(Response::HTTP_BAD_REQUEST, $blobApiError->getStatusCode());
            $this->assertEquals('blob:create-file-data-metadata-does-not-match-type', $blobApiError->getBlobErrorId());
            $this->assertStringContainsString('owner', implode("\n", $blobApiError->getBlobErrorDetails()));
        }
    }

    /**
     * @throws BlobApiError
     * @throws \JsonException
     */
    public function testAddFileWithDraft6SchemaValidatedMetadata(): void
    {
        $metadata = json_encode([
            '@type' => 'DemoDocumentV6',
            'owner' => [
                'id' => 'person-123',
            ],
            'groupId' => 'aae64261-e417-4fa4-b1f5-5c5c7d0c3ba4',
            'status' => 'final',
            'approved' => true,
        ], JSON_THROW_ON_ERROR);
        $blobFile = self::createDemoDocumentBlobFile(self::DEMO_DOCUMENT_DRAFT6_TYPE, $metadata);

        // Draft-06: validates a schema using strict draft-06 mode and referenced definitions.
        $blobFile = $this->fileApi->addFile(self::TEST_BUCKET_IDENTIFIER, $blobFile);

        $this->assertTrue(Uuid::isValid($blobFile->getIdentifier()));
        $this->assertEquals(self::DEMO_DOCUMENT_DRAFT6_TYPE, $blobFile->getType());
        $this->assertEquals($metadata, $blobFile->getMetadata());
    }

    public function testAddFileWithDraft6SpecificConstValidationFails(): void
    {
        $metadata = json_encode([
            '@type' => 'UnexpectedDocument',
            'owner' => [
                'id' => 'person-123',
            ],
            'groupId' => 'aae64261-e417-4fa4-b1f5-5c5c7d0c3ba4',
            'status' => 'final',
            'approved' => true,
        ], JSON_THROW_ON_ERROR);
        $blobFile = self::createDemoDocumentBlobFile(self::DEMO_DOCUMENT_DRAFT6_TYPE, $metadata);

        // Draft-06: enforces the const keyword from a referenced definition.
        try {
            $this->fileApi->addFile(self::TEST_BUCKET_IDENTIFIER, $blobFile);
            $this->fail('Expected BlobApiError');
        } catch (BlobApiError $blobApiError) {
            $this->assertEquals(BlobApiError::CLIENT_ERROR, $blobApiError->getErrorId());
            $this->assertEquals(Response::HTTP_BAD_REQUEST, $blobApiError->getStatusCode());
            $this->assertEquals('blob:create-file-data-metadata-does-not-match-type', $blobApiError->getBlobErrorId());
            $this->assertStringContainsString('DemoDocumentV6', implode("\n", $blobApiError->getBlobErrorDetails()));
        }
    }

    /**
     * @throws BlobApiError
     * @throws \JsonException
     */
    public function testAddFileWithDraft7SchemaValidatedMetadata(): void
    {
        $metadata = json_encode([
            '@type' => 'DemoDocumentV7',
            'owner' => [
                'id' => 'person-123',
            ],
            'groupId' => 'aae64261-e417-4fa4-b1f5-5c5c7d0c3ba4',
            'status' => 'final',
            'approved' => true,
        ], JSON_THROW_ON_ERROR);
        $blobFile = self::createDemoDocumentBlobFile(self::DEMO_DOCUMENT_DRAFT7_TYPE, $metadata);

        // Draft-07: validates a schema using strict draft-07 mode and referenced definitions.
        $blobFile = $this->fileApi->addFile(self::TEST_BUCKET_IDENTIFIER, $blobFile);

        $this->assertTrue(Uuid::isValid($blobFile->getIdentifier()));
        $this->assertEquals(self::DEMO_DOCUMENT_DRAFT7_TYPE, $blobFile->getType());
        $this->assertEquals($metadata, $blobFile->getMetadata());
    }

    public function testAddFileWithDraft7ConstValidationFails(): void
    {
        $metadata = json_encode([
            '@type' => 'DemoDocumentV7',
            'owner' => [
                'id' => 'person-123',
            ],
            'groupId' => 'aae64261-e417-4fa4-b1f5-5c5c7d0c3ba4',
            'status' => 'final',
            'approved' => false,
        ], JSON_THROW_ON_ERROR);
        $blobFile = self::createDemoDocumentBlobFile(self::DEMO_DOCUMENT_DRAFT7_TYPE, $metadata);

        // Draft-07: enforces the const keyword from the main schema.
        try {
            $this->fileApi->addFile(self::TEST_BUCKET_IDENTIFIER, $blobFile);
            $this->fail('Expected BlobApiError');
        } catch (BlobApiError $blobApiError) {
            $this->assertEquals(BlobApiError::CLIENT_ERROR, $blobApiError->getErrorId());
            $this->assertEquals(Response::HTTP_BAD_REQUEST, $blobApiError->getStatusCode());
            $this->assertEquals('blob:create-file-data-metadata-does-not-match-type', $blobApiError->getBlobErrorId());
            $this->assertStringContainsString('true', implode("\n", $blobApiError->getBlobErrorDetails()));
        }
    }

    /**
     * @throws BlobApiError
     * @throws \JsonException
     */
    public function testAddFileWithDraft2019SchemaValidatedMetadata(): void
    {
        $metadata = json_encode([
            '@type' => 'DemoDocument2019',
            'owner' => [
                'id' => 'person-123',
            ],
            'groupId' => 'aae64261-e417-4fa4-b1f5-5c5c7d0c3ba4',
            'status' => 'final',
            'approved' => true,
        ], JSON_THROW_ON_ERROR);
        $blobFile = self::createDemoDocumentBlobFile(self::DEMO_DOCUMENT_DRAFT2019_TYPE, $metadata);

        // Draft-2019-09: resolves $defs from a referenced schema in a subdirectory.
        $blobFile = $this->fileApi->addFile(self::TEST_BUCKET_IDENTIFIER, $blobFile);

        $this->assertTrue(Uuid::isValid($blobFile->getIdentifier()));
        $this->assertEquals(self::DEMO_DOCUMENT_DRAFT2019_TYPE, $blobFile->getType());
        $this->assertEquals($metadata, $blobFile->getMetadata());
    }

    public function testAddFileWithDraft2019DependentRequiredValidationFails(): void
    {
        $metadata = json_encode([
            '@type' => 'DemoDocument2019',
            'owner' => [
                'id' => 'person-123',
            ],
            'groupId' => 'aae64261-e417-4fa4-b1f5-5c5c7d0c3ba4',
            'status' => 'final',
            'approved' => true,
            'reviewer' => 'person-456',
        ], JSON_THROW_ON_ERROR);
        $blobFile = self::createDemoDocumentBlobFile(self::DEMO_DOCUMENT_DRAFT2019_TYPE, $metadata);

        // Draft-2019-09: enforces dependentRequired, split from the older dependencies keyword.
        try {
            $this->fileApi->addFile(self::TEST_BUCKET_IDENTIFIER, $blobFile);
            $this->fail('Expected BlobApiError');
        } catch (BlobApiError $blobApiError) {
            $this->assertEquals(BlobApiError::CLIENT_ERROR, $blobApiError->getErrorId());
            $this->assertEquals(Response::HTTP_BAD_REQUEST, $blobApiError->getStatusCode());
            $this->assertEquals('blob:create-file-data-metadata-does-not-match-type', $blobApiError->getBlobErrorId());
            $this->assertStringContainsString('reviewedAt', implode("\n", $blobApiError->getBlobErrorDetails()));
        }
    }

    private static function createDemoDocumentBlobFile(string $type, string $metadata): BlobFile
    {
        $blobFile = new BlobFile();
        $blobFile->setPrefix('prefix');
        $blobFile->setFileName(self::TEST_FILENAME);
        $blobFile->setFile(self::TEST_FILE_CONTENTS);
        $blobFile->setType($type);
        $blobFile->setMetadata($metadata);

        return $blobFile;
    }
}
