<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Tests;

use Dbp\Relay\BlobBundle\Helper\PatchRequestParser;
use Dbp\Relay\BlobBundle\TestUtils\BlobTestUtils;
use Dbp\Relay\CoreBundle\Exception\ApiError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class PatchRequestParserTest extends TestCase
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

        $this->expectException(ApiError::class);
        try {
            (new PatchRequestParser())->withParsedRequest($request, function (): void {
                $this->fail('Invalid input must not reach the callback');
            });
        } catch (ApiError $error) {
            $this->assertSame($status, $error->getStatusCode());
            throw $error;
        }
    }

    public function testPatchAcceptsMixedCaseMediaType(): void
    {
        $body = "--TestBoundary\r\nContent-Disposition: form-data; name=\"metadata\"\r\n\r\n{}\r\n"
            ."--TestBoundary--\r\n";
        $request = Request::create('/blob/files/test-file', 'PATCH',
            server: ['CONTENT_TYPE' => 'Multipart/Form-Data; boundary=TestBoundary'], content: $body);

        (new PatchRequestParser())->withParsedRequest($request, function (Request $parsedRequest): void {
            $this->assertSame('{}', $parsedRequest->request->get('metadata'));
        });
    }

    public function testCleanupRemovesAllUploadsIncludingNestedFields(): void
    {
        $paths = (new PatchRequestParser())->withParsedRequest(
            BlobTestUtils::createPatchUploadRequest(['file', 'unexpected[nested][]']),
            function (Request $parsedRequest): array {
                $paths = [];
                $files = $parsedRequest->files->all();
                array_walk_recursive($files, function (UploadedFile $file) use (&$paths): void {
                    $paths[] = $file->getPathname();
                    $this->assertSame('upload contents', $file->getContent());
                });

                return $paths;
            }
        );
        $this->assertCount(2, $paths);
        foreach ($paths as $path) {
            $this->assertFileDoesNotExist($path);
        }
    }
}
