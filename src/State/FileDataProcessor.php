<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\State;

use ApiPlatform\Metadata\DeleteOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Dbp\Relay\BlobBundle\Entity\FileData;
use Dbp\Relay\BlobBundle\Service\BlobService;
use Dbp\Relay\CoreBundle\Exception\ApiError;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;

/**
 * @psalm-suppress MissingTemplateParam
 */
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
            $docBucket = $this->blobService->getBucketByInternalIdFromDatabase($data->getInternalBucketID());
            $this->blobService->writeToTablesAndRemoveFileData($data, $docBucket->getCurrentBucketSize() - $data->getFileSize());
        }

        return $data;
    }
}
