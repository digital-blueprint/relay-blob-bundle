<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Tests;

use Dbp\Relay\BlobBundle\Helper\BlobUtils;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;

class BlobUtilsTest extends TestCase
{
    public function testConvertMultipartPatchRequest(): void
    {
        $fields = ['fileName' => 'updated.txt', 'metadata' => '{"key":"value"}'];
        $body = new FormDataPart($fields + ['file' => new DataPart('file contents', 'upload.txt', 'text/plain')]);
        $request = Request::create('/blob/files/test?bucketIdentifier=test-bucket', 'PATCH',
            server: ['CONTENT_TYPE' => $body->getPreparedHeaders()->get('Content-Type')->getBodyAsString()],
            content: $body->bodyToString());

        $convertedRequest = BlobUtils::convertPatchRequest($request);

        $this->assertSame($fields, $convertedRequest->request->all());
        $this->assertSame('test-bucket', $convertedRequest->query->get('bucketIdentifier'));
        $this->assertSame('PATCH', $convertedRequest->getMethod());
        $file = $convertedRequest->files->get('file');
        $this->assertInstanceOf(UploadedFile::class, $file);
        $this->assertSame('upload.txt', $file->getClientOriginalName());
        $this->assertSame('file contents', $file->getContent());
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
