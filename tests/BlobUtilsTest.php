<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Tests;

use Dbp\Relay\BlobBundle\Helper\BlobUtils;
use PHPUnit\Framework\TestCase;

class BlobUtilsTest extends TestCase
{
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
