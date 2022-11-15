<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\DataProvider;

use ApiPlatform\Core\DataProvider\ItemDataProviderInterface;
use ApiPlatform\Core\DataProvider\RestrictedDataProviderInterface;
use Dbp\Relay\BlobBundle\Entity\FileData;
use Dbp\Relay\BlobBundle\Service\BlobService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

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

        if ($operationName === "get" || $operationName === "put") {
            $fileData = $this->blobService->getLink($fileData);
        }

        return $fileData;
    }
}
