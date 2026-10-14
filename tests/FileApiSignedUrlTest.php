<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Tests;

use Dbp\Relay\BlobBundle\Configuration\ConfigurationService;
use Dbp\Relay\BlobBundle\Helper\SignatureUtils;
use Dbp\Relay\BlobLibrary\Api\BlobApi;
use Dbp\Relay\CoreBundle\Exception\ApiError;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\RouterInterface;

class FileApiSignedUrlTest extends KernelTestCase
{
    private const BUCKET_IDENTIFIER = 'test-bucket';
    private const FILE_IDENTIFIER = '019746a0-dedf-767e-b8f6-c5aaa9dc9f7c';
    private const DOWNLOAD_PATH = '/blob/files/'.self::FILE_IDENTIFIER.'/download';

    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function testDefaultUriWithBasePath(): void
    {
        $this->assertNull(self::getContainer()->get(RequestStack::class)->getCurrentRequest());

        $url = $this->createSignedDownloadUrl();
        $this->assertStringStartsWith(Kernel::DEFAULT_URI.self::DOWNLOAD_PATH.'?', $url);
        $this->assertValidSignature($url, '/deployment');
    }

    public function testCurrentRequestTakesPrecedence(): void
    {
        $request = Request::create('https://request.test/blob/files');
        self::getContainer()->get(RequestStack::class)->push($request);
        self::getContainer()->get(RouterInterface::class)->getContext()->fromRequest($request);

        $url = $this->createSignedDownloadUrl();
        $this->assertStringStartsWith('https://request.test'.self::DOWNLOAD_PATH.'?', $url);
        $this->assertValidSignature($url);
    }

    public function testSubrequestUsesItsOwnHostAndBasePath(): void
    {
        $requestStack = self::getContainer()->get(RequestStack::class);
        $requestStack->push(Request::create('https://main.test/blob/files'));
        $request = Request::create('https://subrequest.test/app/blob/files', server: [
            'SCRIPT_NAME' => '/app/index.php',
            'SCRIPT_FILENAME' => '/srv/public/index.php',
        ]);
        $requestStack->push($request);
        self::getContainer()->get(RouterInterface::class)->getContext()->fromRequest($request);

        $url = $this->createSignedDownloadUrl();
        $this->assertStringStartsWith('https://subrequest.test/app'.self::DOWNLOAD_PATH.'?', $url);
        $this->assertValidSignature($url, '/app');
    }

    public function testChangedEndpointIsRejected(): void
    {
        $url = str_replace(self::DOWNLOAD_PATH, '/blob/files/other/download', $this->createSignedDownloadUrl());

        $this->assertInvalidSignature($url);
    }

    public function testUnsignedPrefixOverrideIsRejected(): void
    {
        $this->assertInvalidSignature($this->createSignedDownloadUrl().'&prefix=other');
    }

    public function testUnsignedQueryParameterIsRejected(): void
    {
        $this->assertInvalidSignature($this->createSignedDownloadUrl().'&includeData=1');
    }

    public function testDuplicateSignatureIsRejected(): void
    {
        $url = $this->createSignedDownloadUrl();
        $signature = Request::create($url)->query->get('sig');

        $this->assertInvalidSignature($url.'&sig='.$signature);
    }

    private function createSignedDownloadUrl(): string
    {
        $blobApi = BlobApi::createFromConfig(BlobApi::getCustomModeConfig(self::BUCKET_IDENTIFIER), self::getContainer());

        return $blobApi->createSignedUrl('GET', parameters: ['prefix' => 'a b/+'],
            identifier: self::FILE_IDENTIFIER, action: 'download');
    }

    private function assertValidSignature(string $url, string $basePath = ''): void
    {
        $configuration = self::getContainer()->get(ConfigurationService::class);
        $request = Request::create($url, server: [
            'SCRIPT_NAME' => $basePath.'/index.php',
            'SCRIPT_FILENAME' => '/srv/public/index.php',
        ]);
        SignatureUtils::checkSignature('test', $configuration, $request, $request->query->all(), ['GET']);
        $this->assertTrue($request->query->has('sig'));
    }

    private function assertInvalidSignature(string $url): void
    {
        $this->expectException(ApiError::class);
        $this->expectExceptionMessage('Signature invalid');
        $this->assertValidSignature($url, '/deployment');
    }
}
