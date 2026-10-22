<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Tests;

use Dbp\Relay\BlobBundle\Helper\BlobUtils;
use Dbp\Relay\CoreBundle\Exception\ApiError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class BlobUtilsTest extends TestCase
{
    public static function invalidPatchRequests(): iterable
    {
        yield 'unsupported media type' => ['application/merge-patch+json', '', Response::HTTP_UNSUPPORTED_MEDIA_TYPE];
        yield 'missing boundary' => ['multipart/form-data', '', Response::HTTP_BAD_REQUEST];
        yield 'header without value' => ['multipart/form-data; boundary=TestBoundary',
            "--TestBoundary\r\nInvalid-Header\r\n\r\nvalue\r\n--TestBoundary--\r\n", Response::HTTP_BAD_REQUEST];
    }

    #[DataProvider('invalidPatchRequests')]
    public function testPatchRejectsInvalidInput(string $contentType, string $body, int $status): void
    {
        $request = Request::create('/blob/files/test-file', 'PATCH', server: ['CONTENT_TYPE' => $contentType], content: $body);

        try {
            BlobUtils::convertPatchRequest($request);
            $this->fail('Expected an API error');
        } catch (ApiError $error) {
            $this->assertSame($status, $error->getStatusCode());
        }
    }

    public function testPatchAcceptsMixedCaseMediaType(): void
    {
        $body = "--TestBoundary\r\nContent-Disposition: form-data; name=\"metadata\"\r\n\r\n{}\r\n"
            ."--TestBoundary--\r\n";
        $request = Request::create('/blob/files/test-file', 'PATCH',
            server: ['CONTENT_TYPE' => 'Multipart/Form-Data; boundary=TestBoundary'], content: $body);

        $convertedRequest = BlobUtils::convertPatchRequest($request);
        $this->assertSame('{}', $convertedRequest->request->get('metadata'));
    }

    public function testFormatBytes(): void
    {
        $this->assertSame('0 B', BlobUtils::formatBytes(0));
        $this->assertSame('1 B', BlobUtils::formatBytes(1));
        $this->assertSame('1023 B', BlobUtils::formatBytes(1023));

        $this->assertSame('1 KB', BlobUtils::formatBytes(1024));
        $this->assertSame('1.5 KB', BlobUtils::formatBytes(1536));
        $this->assertSame('1023.9 KB', BlobUtils::formatBytes(1024 * 1024 - 100));

        $this->assertSame('1 MB', BlobUtils::formatBytes(1024 * 1024));
        $this->assertSame('1.5 MB', BlobUtils::formatBytes(1024 * 1024 + 512 * 1024));
        $this->assertSame('512 MB', BlobUtils::formatBytes(512 * 1024 * 1024));

        $this->assertSame('1 GB', BlobUtils::formatBytes(1024 * 1024 * 1024));
        $this->assertSame('1.5 GB', BlobUtils::formatBytes(1024 * 1024 * 1024 + 512 * 1024 * 1024));
    }
}
