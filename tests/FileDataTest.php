<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Tests;

use Dbp\Relay\BlobBundle\Entity\FileData;
use PHPUnit\Framework\TestCase;

class FileDataTest extends TestCase
{
    public function testGetMetadataNull(): void
    {
        $fileData = new FileData();
        $this->assertNull($fileData->getMetadata());
    }

    public function testGetMetadataNewValue(): void
    {
        // A real metadata value is a JSON object and is stored as-is (single encoded).
        $metadata = '{"foo":"bar"}';
        $fileData = new FileData();
        $fileData->setMetadata($metadata);
        $this->assertSame($metadata, $fileData->getMetadata());
    }

    public function testGetMetadataLegacyDoubleEncodedValue(): void
    {
        // Legacy values were double JSON encoded in the database (stored as a JSON string
        // starting with '"'). They have to be decoded once to get the real value back.
        $metadata = '{"foo":"bar"}';
        $doubleEncoded = json_encode($metadata);
        $this->assertSame('"{\\"foo\\":\\"bar\\"}"', $doubleEncoded);

        $fileData = new FileData();
        $fileData->setMetadata($doubleEncoded);
        $this->assertSame($metadata, $fileData->getMetadata());
    }

    public function testGetMetadataLegacyHashStillMatches(): void
    {
        // The metadataHash of legacy entries was computed over the original (single encoded)
        // value, so reading a legacy double encoded value has to yield the same hash again.
        $metadata = '{"foo":"bar"}';
        $expectedHash = hash('sha256', $metadata);

        $fileData = new FileData();
        $fileData->setMetadata(json_encode($metadata));

        $this->assertSame($expectedHash, hash('sha256', $fileData->getMetadata()));
    }

    public function testGetMetadataEmptyString(): void
    {
        $fileData = new FileData();
        $fileData->setMetadata('');
        $this->assertSame('', $fileData->getMetadata());
    }
}
