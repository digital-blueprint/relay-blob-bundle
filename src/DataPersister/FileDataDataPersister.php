<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\DataPersister;

use ApiPlatform\Core\DataPersister\ContextAwareDataPersisterInterface;
use Dbp\Relay\BlobBundle\Entity\FileData;
use Dbp\Relay\BlobBundle\Service\BlobService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

class FileDataDataPersister extends AbstractController implements ContextAwareDataPersisterInterface
{
    /**
     * @var BlobService
     */
    private $blobService;

    public function __construct(BlobService $blobService)
    {
        $this->blobService = $blobService;
    }

    public function supports($data, array $context = []): bool
    {
        return $data instanceof FileData;
    }

    /**
     * @param mixed $data
     *
     * @return FileData|void
     */
    public function persist($data, array $context = [])
    {
        // no need to check, because signature is checked by getting the data

        assert($data instanceof FileData);

        if (array_key_exists('item_operation_name', $context) && $context['item_operation_name'] === 'put_exists_until') {
            $this->blobService->increaseExistsUntil($data);
        }

        return $data;
    }

    /**
     * @param mixed $data
     */
    public function remove($data, array $context = []): void
    {
        // no need to check, because signature is checked by getting the data
//        $i = $data->getIdentifier();
//        echo "    FileDataPersister::remove(identifier: $i)\n";

        assert($data instanceof FileData);

        $this->blobService->removeFileData($data);
    }
}
