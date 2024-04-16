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
            try {
                $docBucket = $this->blobService->getBucketByInternalIdFromDatabase($data->getInternalBucketID());
                $docBucket->setCurrentBucketSize($docBucket->getCurrentBucketSize() - $data->getFileSize());
                $this->blobService->saveBucketData($docBucket);
            } catch (\Exception $e) {
                throw ApiError::withDetails(Response::HTTP_INTERNAL_SERVER_ERROR, 'Error while writing to the bucket_sizes table', 'blob:delete-file-data-save-file-size-failed');
            }

            try {
                $this->blobService->removeFileData($data);
            } catch (\Exception $e) {
                try {
                    $docBucket = $this->blobService->getBucketByInternalIdFromDatabase($data->getInternalBucketID());
                    $docBucket->setCurrentBucketSize($docBucket->getCurrentBucketSize() - $data->getFileSize());
                    $this->blobService->saveBucketData($docBucket);
                } catch (\Exception $e) {
                    throw ApiError::withDetails(Response::HTTP_INTERNAL_SERVER_ERROR, 'Error while writing to the bucket_sizes table', 'blob:delete-file-data-restore-file-size-failed');
                }

                throw ApiError::withDetails(Response::HTTP_INTERNAL_SERVER_ERROR, 'Error while removing the file', 'blob:delete-file-data-remove-file');
            }
        }

        return $data;
    }
}
