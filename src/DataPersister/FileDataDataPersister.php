<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\DataPersister;

use ApiPlatform\Core\DataPersister\ContextAwareDataPersisterInterface;
use ApiPlatform\Core\DataPersister\DataPersisterInterface;
use Dbp\Relay\CoreBundle\Exception\ApiError;
use Dbp\Relay\BlobBundle\Entity\FileData;
use Dbp\Relay\BlobBundle\Service\BlobService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;

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
     * @return Request
     */
    public function persist($data, array $context = [])
    {
        dump("-------------------------- persist");
        if (array_key_exists('item_operation_name', $context) && $context['item_operation_name'] == "put") {
            $filedata = $data;
            assert($filedata instanceof FileData);

            $metadata = $filedata->getAdditionalMetadata();
            try {
                json_decode($metadata, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw ApiError::withDetails(Response::HTTP_UNPROCESSABLE_ENTITY, 'The addtional Metadata doesn\'t contain valid json!', 'blob:blob-service-invalid-json');
            }

            $this->blobService->renameFileData($filedata);
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
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $filedata = $data;
        assert($filedata instanceof FileData);

        $this->blobService->removeFileData($filedata);
    }
}
