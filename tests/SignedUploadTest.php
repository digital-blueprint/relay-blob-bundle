<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Tests;

use Dbp\Relay\BlobBundle\Configuration\ConfigurationService;
use Dbp\Relay\BlobBundle\Entity\FileData;
use Dbp\Relay\BlobBundle\Helper\SignatureUtils;
use Dbp\Relay\BlobBundle\Service\BlobService;
use Dbp\Relay\BlobBundle\TestUtils\TestEntityManager;
use Dbp\Relay\BlobLibrary\Api\BlobApi;
use Dbp\Relay\CoreBundle\Exception\ApiError;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class SignedUploadTest extends KernelTestCase
{
    private const METADATA = '{"foo":"bar"}';

    protected function setUp(): void
    {
        self::bootKernel();
        TestEntityManager::setUpBlobEntityManager(self::getContainer());
    }

    public static function uploadCases(): iterable
    {
        foreach (['POST', 'PATCH'] as $method) {
            foreach (['query', 'body', 'both'] as $source) {
                yield $method.' '.$source => [$method, $source];
            }
        }
    }

    #[DataProvider('uploadCases')]
    public function testMatchingHashes(string $method, string $source): void
    {
        $hashes = $this->getHashes();
        $request = $this->createRequest($method, $source === 'body' ? [] : $hashes,
            $source === 'query' ? [] : $hashes);

        $fileData = $this->validateRequest($request);

        $this->assertSame($hashes['fileHash'], $fileData->getFileHash());
        $this->assertSame(self::METADATA, $fileData->getMetadata());
        $this->assertNotNull($fileData->getFile());
    }

    public static function hashParameters(): iterable
    {
        yield 'file' => ['fileHash', 'file', 'File hash does not match the uploaded file'];
        yield 'metadata' => ['metadataHash', 'metadata', 'Metadata hash does not match the submitted metadata'];
    }

    #[DataProvider('hashParameters')]
    public function testMismatchedContentIsRejected(string $parameter, string $input, string $message): void
    {
        $hashes = $this->getHashes();
        $hashes[$parameter] = hash('sha256', 'different content');

        $this->expectException(ApiError::class);
        $this->expectExceptionMessage($message);
        $this->validateRequest($this->createRequest('PATCH', $hashes));
    }

    #[DataProvider('hashParameters')]
    public function testMissingInputIsRejected(string $parameter, string $input): void
    {
        $request = $this->createRequest('PATCH', $this->getHashes());
        $request->files->remove($input);
        $request->request->remove($input);

        $this->expectException(ApiError::class);
        $this->expectExceptionMessage($parameter.' requires');
        $this->validateRequest($request);
    }

    #[DataProvider('hashParameters')]
    public function testConflictingBodyHashIsRejected(string $parameter): void
    {
        $request = $this->createRequest('PATCH', $this->getHashes(), [
            $parameter => hash('sha256', 'different content'),
        ]);

        $this->expectException(ApiError::class);
        $this->expectExceptionMessage($parameter.' in the body does not match the URL');
        $this->validateRequest($request);
    }

    #[DataProvider('hashParameters')]
    public function testSignedHashCannotBeChanged(string $parameter): void
    {
        $hashes = $this->getHashes();
        $url = str_replace($hashes[$parameter], hash('sha256', 'different content'),
            $this->createRequest('PATCH', $hashes)->getUri());

        $this->expectException(ApiError::class);
        $this->expectExceptionMessage('Signature invalid');
        $this->validateRequest(Request::create($url, 'PATCH', server: $this->getServer()));
    }

    public static function requestMethods(): iterable
    {
        yield 'POST' => ['POST'];
        yield 'PATCH' => ['PATCH'];
    }

    #[DataProvider('requestMethods')]
    public function testAllowedFieldsAcceptsListedFieldsAndImplicitHashes(string $method): void
    {
        $request = $this->createRequest($method, ['allowedFields' => 'file, fileName,metadata'],
            $this->getHashes() + ['fileName' => 'test.txt']);

        $fileData = $this->validateRequest($request);

        $this->assertSame('test.txt', $fileData->getFileName());
        $this->assertSame(self::METADATA, $fileData->getMetadata());
        $this->assertSame($this->getHashes()['fileHash'], $fileData->getFileHash());
        $this->assertNotNull($fileData->getFile());
    }

    public static function disallowedFields(): iterable
    {
        yield 'file' => ['metadata', 'file'];
        yield 'metadata' => ['file', 'metadata'];
        yield 'fileName' => ['file,metadata', 'fileName', ['fileName' => '']];
        yield 'unknown field' => ['file,metadata', 'unknown', ['unknown' => 'value']];
        yield 'body cannot override allowlist' => ['file,metadata', 'allowedFields', ['allowedFields' => 'file,fileName,metadata']];
        yield 'empty allowlist' => ['', 'metadata'];
    }

    #[DataProvider('disallowedFields')]
    public function testDisallowedBodyFieldIsRejected(string $allowedFields, string $field, array $body = []): void
    {
        $request = $this->createRequest('PATCH', ['allowedFields' => $allowedFields], $body);

        $this->assertBadRequest($request, 'Body field '.$field.' is not allowed by allowedFields');
    }

    #[DataProvider('requestMethods')]
    public function testOmittedAllowedFieldsPreservesUnrestrictedHandling(string $method): void
    {
        $request = $this->createRequest($method, [], ['fileName' => 'test.txt', 'unknown' => 'ignored']);

        $this->assertSame('test.txt', $this->validateRequest($request)->getFileName());
    }

    #[DataProvider('hashParameters')]
    public function testImplicitHashesStillRequireContent(string $parameter): void
    {
        $request = $this->createRequest('PATCH', ['allowedFields' => ''], [$parameter => $this->getHashes()[$parameter]]);
        $request->request->remove('metadata');
        $request->files->remove('file');

        $this->assertBadRequest($request, $parameter.' requires');
    }

    #[DataProvider('hashParameters')]
    public function testImplicitHashesStillValidateContent(string $parameter, string $input, string $message): void
    {
        $request = $this->createRequest('PATCH', ['allowedFields' => 'file,metadata'],
            [$parameter => hash('sha256', 'different content')]);

        $this->assertBadRequest($request, $message);
    }

    public function testAllowedFieldsMustBeAString(): void
    {
        $request = $this->createRequest('PATCH', ['allowedFields' => ['file', 'metadata']]);

        $this->assertBadRequest($request, 'Parameter allowedFields must be a string');
    }

    #[DataProvider('requestMethods')]
    public function testAllowedFieldsCannotBeTamperedWith(string $method): void
    {
        $url = $this->createRequest($method, ['allowedFields' => 'metadata'])->getUri();
        $url = str_replace('allowedFields=metadata', 'allowedFields=metadata%2Cfile', $url);

        $this->expectException(ApiError::class);
        $this->expectExceptionMessage('Signature invalid');
        $this->validateRequest(Request::create($url, $method, server: $this->getServer()));
    }

    private function assertBadRequest(Request $request, string $message): void
    {
        try {
            $this->validateRequest($request);
            $this->fail('Expected a bad request');
        } catch (ApiError $error) {
            $this->assertSame(Response::HTTP_BAD_REQUEST, $error->getStatusCode());
            $this->assertStringContainsString($message, $error->getMessage());
        }
    }

    private function getHashes(): array
    {
        return [
            'fileHash' => hash_file('sha256', __DIR__.'/test.txt'),
            'metadataHash' => hash('sha256', self::METADATA),
        ];
    }

    private function createRequest(string $method, array $queryParameters, array $body = []): Request
    {
        $blobApi = BlobApi::createFromConfig(BlobApi::getCustomModeConfig('test-bucket'), self::getContainer());
        $url = $blobApi->createSignedUrl($method, parameters: $queryParameters,
            identifier: $method === 'PATCH' ? 'test-file' : null);

        return Request::create($url, $method, $body + ['metadata' => self::METADATA], files: [
            'file' => new UploadedFile(__DIR__.'/test.txt', 'test.txt', test: true),
        ], server: $this->getServer());
    }

    private function getServer(): array
    {
        return [
            'SCRIPT_NAME' => '/deployment/index.php',
            'SCRIPT_FILENAME' => '/srv/public/index.php',
        ];
    }

    private function validateRequest(Request $request): FileData
    {
        SignatureUtils::checkSignature(self::getContainer()->get(ConfigurationService::class),
            $request, ['POST', 'PATCH']);

        return self::getContainer()->get(BlobService::class)
            ->setUpFileDataFromRequest(new FileData(), $request);
    }
}
