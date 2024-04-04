<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\State;

use ApiPlatform\Metadata\DeleteOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Dbp\Relay\BlobBundle\Entity\FileData;
use Dbp\Relay\BlobBundle\Service\BlobService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

class FileDataProcessor extends AbstractController implements ProcessorInterface
{
    /**
     * @var BlobService
     */
    private $blobService;

    public function __construct(BlobService $blobService)
    {
        $this->blobService = $blobService;
    }

    /**
     * @return mixed
     */
    public function process($data, Operation $operation, array $uriVariables = [], array $context = [])
    {
        // no need to check, because signature is checked by getting the data
        assert($data instanceof FileData);

        if ($operation instanceof DeleteOperationInterface) {
            $docBucket = $this->blobService->getBucketByIdFromDatabase($data->getInternalBucketID());
            $docBucket->setCurrentBucketSize($docBucket->getCurrentBucketSize() - $data->getFileSize());

            $this->blobService->saveBucketData($docBucket);

            $this->blobService->removeFileData($data);
        }

        return $data;
    }
}
