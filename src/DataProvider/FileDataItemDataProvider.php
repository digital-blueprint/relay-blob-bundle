<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\DataProvider;

use ApiPlatform\Core\DataProvider\ItemDataProviderInterface;
use ApiPlatform\Core\DataProvider\RestrictedDataProviderInterface;
use ApiPlatform\Core\Exception\ResourceClassNotSupportedException;
use Dbp\Relay\BlobBundle\Entity\FileData;
use Dbp\Relay\BlobBundle\Service\BlobService;
use Dbp\Relay\CoreBundle\Exception\ApiError;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class FileDataItemDataProvider extends AbstractController implements ItemDataProviderInterface, RestrictedDataProviderInterface
{
    /**
     * @var BlobService
     */
    private $blobService;

    public function __construct(BlobService $blobService)
    {
        $this->blobService = $blobService;
    }

    public function supports(string $resourceClass, string $operationName = null, array $context = []): bool
    {
        return FileData::class === $resourceClass;
    }

    public function getItem(string $resourceClass, $id, string $operationName = null, array $context = []): ?FileData
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $fileData = $this->blobService->getFileData($id);
        $fileData = $this->blobService->setBucket($fileData);

        if ($operationName == "get" || $operationName == "put") {
            $fileData = $this->blobService->getLink($fileData);
        }

        return $fileData;
    }
}
