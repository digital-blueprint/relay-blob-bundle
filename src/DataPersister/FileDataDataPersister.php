<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\DataPersister;

use ApiPlatform\Core\DataPersister\ContextAwareDataPersisterInterface;
use Dbp\Relay\BlobBundle\Entity\FileData;
use Dbp\Relay\BlobBundle\Service\BlobService;
use Dbp\Relay\CoreBundle\Exception\ApiError;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;

class FileDataDataPersister extends AbstractController implements ContextAwareDataPersisterInterface
{
    /**
     * @var BlobService
     */
    private $blobService;

    /**
     * @var RequestStack
     */
    private $requestStack;

    public function __construct(BlobService $blobService, RequestStack $requestStack)
    {
        $this->blobService = $blobService;
        $this->requestStack = $requestStack;
    }

    public function supports($data, array $context = []): bool
    {
        return $data instanceof FileData;
    }

    public function persist($data, array $context = [])
    {
        if (array_key_exists('item_operation_name', $context) && $context['item_operation_name'] === 'put') {
            $filedata = $data;
            assert($filedata instanceof FileData);

            $metadata = $filedata->getAdditionalMetadata();
            if ($metadata) {
                try {
                    json_decode($metadata, true, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException $e) {
                    throw ApiError::withDetails(Response::HTTP_UNPROCESSABLE_ENTITY, 'The addtional Metadata doesn\'t contain valid json!', 'blob:blob-service-invalid-json');
                }
            }

            $this->blobService->renameFileData($filedata);
        }

        if (array_key_exists('item_operation_name', $context) && $context['item_operation_name'] === 'put_exists_until') {
            $filedata = $data;
            assert($filedata instanceof FileData);

            $this->blobService->increaseExistsUntil($filedata);
        }

        return $data;
    }

    /**
     * @param mixed $data
     *
     * @return void
     */
    public function remove($data, array $context = [])
    {
        $filedata = $data;
        assert($filedata instanceof FileData);

        $this->blobService->removeFileData($filedata);
    }
}
