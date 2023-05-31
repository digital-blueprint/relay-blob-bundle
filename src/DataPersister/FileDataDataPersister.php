<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\DataPersister;

use ApiPlatform\Core\DataPersister\ContextAwareDataPersisterInterface;
use Dbp\Relay\BlobBundle\Entity\FileData;
use Dbp\Relay\BlobBundle\Service\BlobService;
use Dbp\Relay\CoreBundle\Exception\ApiError;
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
     * @return FileData|void
     */
    public function persist($data, array $context = [])
    {
        // no need to check, because signature is checked by getting the data

        assert($data instanceof FileData);

        dump("presist");

        if (array_key_exists('item_operation_name', $context) && $context['item_operation_name'] === 'put') {
            $metadata = $data->getAdditionalMetadata();
            if ($metadata) {
                try {
                    json_decode($metadata, true, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException $e) {
                    throw ApiError::withDetails(Response::HTTP_UNPROCESSABLE_ENTITY, 'The additional Metadata doesn\'t contain valid json!', 'blob:blob-service-invalid-json');
                }
            }
            dump("rename");
            $this->blobService->renameFileData($data);
        }

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

    private function generateChecksum($pathInfo, $validUntil, $path, $secret): string
    {
        return hash_hmac('sha256', $pathInfo.'?'.'validUntil='.str_replace(" ", "+", $validUntil), $secret);
    }
}
