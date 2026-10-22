<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Tests;

use Dbp\Relay\BlobBundle\ApiPlatform\FileDataProcessor;
use Dbp\Relay\BlobBundle\Configuration\ConfigurationService;
use Dbp\Relay\BlobBundle\Entity\FileData;
use Dbp\Relay\BlobBundle\Service\BlobService;
use Dbp\Relay\BlobBundle\TestUtils\BlobTestUtils;
use Dbp\Relay\CoreBundle\Exception\ApiError;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

class FileDataProcessorTest extends TestCase
{
    public function testCleanupAfterAllowedFieldsRejection(): void
    {
        $request = BlobTestUtils::createPatchUploadRequest(['file']);
        $request->query->set('allowedFields', 'metadata');
        $path = null;

        // Run the real field validation, which must reject the upload before using other services.
        $validatingService = $this->createPartialMock(BlobService::class, ['updateFile']);
        $blobService = $this->createMock(BlobService::class);
        $blobService->expects($this->once())->method('setUpFileDataFromRequest')
            ->willReturnCallback(function (FileData $data, Request $parsedRequest) use (&$path, $validatingService): FileData {
                $file = $parsedRequest->files->get('file');
                $this->assertInstanceOf(UploadedFile::class, $file);
                $path = $file->getPathname();
                $this->assertFileExists($path);

                return $validatingService->setUpFileDataFromRequest($data, $parsedRequest);
            });
        $blobService->expects($this->never())->method('updateFile');
        $requestStack = new RequestStack();
        $requestStack->push($request);
        $processor = new FileDataProcessor($blobService, $requestStack,
            $this->createMock(ConfigurationService::class));
        $updateItem = new \ReflectionMethod(FileDataProcessor::class, 'updateItem');

        $this->expectException(ApiError::class);
        $this->expectExceptionMessage('Body field file is not allowed by allowedFields');
        try {
            $updateItem->invoke($processor, 'test-file', new FileData(), new FileData(), []);
        } finally {
            $this->assertNotNull($path);
            $this->assertFileDoesNotExist($path);
        }
    }
}
