<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\State;

use ApiPlatform\Metadata\DeleteOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Put;
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
            $this->blobService->removeFileData($data);
        } elseif ($operation instanceof Put && $operation->getName() === 'put_exists_until') {
            $this->blobService->increaseExistsUntil($data);
        }

        return $data;
    }
}
