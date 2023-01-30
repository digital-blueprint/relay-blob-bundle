<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\DataProvider;

use Dbp\Relay\BlobBundle\Entity\FileData;
use Dbp\Relay\BlobBundle\Helper\DenyAccessUnlessCheckSignature;
use Dbp\Relay\BlobBundle\Service\BlobService;
use Dbp\Relay\CoreBundle\DataProvider\AbstractDataProvider;
use Dbp\Relay\CoreBundle\Exception\ApiError;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;

class FileDataDataProvider extends AbstractDataProvider
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
        parent::__construct($requestStack);

        $this->blobService = $blobService;
        $this->requestStack = $requestStack;
    }

    protected function onOperationStart(int $operation)
    {
    }

    protected function getResourceClass(): string
    {
        return FileData::class;
    }

    /* getItemById() is overwritten with getFileDataById() because we want filters here for checking the signature */
    public function getItem(string $resourceClass, $id, string $operationName = null, array $context = []): ?object
    {
        $this->onOperationStart(self::GET_ITEM_OPERATION);

        $filters = $context['filters'] ?? [];

        return $this->getFileDataById($id, $filters);
    }

    protected function getFileDataById($id, array $filters): object
    {
//        echo "     FileDataProvider::getFileDataById($id, filters)\n";
        $sig = $this->requestStack->getCurrentRequest()->headers->get('x-dbp-signature', '');
        if (!$sig) {
            throw ApiError::withDetails(Response::HTTP_UNAUTHORIZED, 'Signature missing', 'blob:createFileData-missing-sig');
        }
        $bucketId = $filters['bucketID'] ?? '';
        assert(is_string($bucketId));
        if (!$bucketId) {
            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'BucketID is missing', 'blob:get-files-by-prefix-missing-bucketID');
        }
        $bucket = $this->blobService->configurationService->getBucketByID($bucketId);
        if (!$bucket) {
            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'BucketID is not configured', 'blob:get-files-by-prefix-not-configured-bucketID');
        }

        $secret = $bucket->getPublicKey();
        $this->checkSignature($secret, $filters);

        $fileData = $this->blobService->getFileData($id);

        if (!$fileData) {
            throw ApiError::withDetails(Response::HTTP_NOT_FOUND, 'FileData was not found!', 'blob:fileData-not-found');
        }

        $fileData = $this->blobService->setBucket($fileData);
        if ($this->requestStack->getCurrentRequest()->getMethod() !== 'DELETE') {
            $fileData = $this->blobService->getLink($fileData);
            $this->blobService->saveFileData($fileData);
        }

        return $fileData;
    }

    protected function getItemById($id, array $options = []): object
    {
        throw ApiError::withDetails(Response::HTTP_NOT_FOUND, 'Should not be called!', 'blob:wrong-function');
    }

    protected function getPage(int $currentPageNumber, int $maxNumItemsPerPage, array $filters = [], array $options = []): array
    {
        $sig = $this->requestStack->getCurrentRequest()->headers->get('x-dbp-signature', '');
        if (!$sig) {
            throw ApiError::withDetails(Response::HTTP_UNAUTHORIZED, 'Signature missing', 'blob:createFileData-missing-sig');
        }
        $bucketId = $filters['bucketID'] ?? '';
        assert(is_string($bucketId));
        if (!$bucketId) {
            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'BucketID is missing', 'blob:get-files-by-prefix-missing-bucketID');
        }
        $bucket = $this->blobService->configurationService->getBucketByID($bucketId);
        if (!$bucket) {
            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'BucketID is not configured', 'blob:get-files-by-prefix-not-configured-bucketID');
        }

        $secret = $bucket->getPublicKey();
        $this->checkSignature($secret, $filters);

        $prefix = $filters['prefix'] ?? '';

        if (!$bucket->getService()) {
            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'BucketService is not configured', 'blob:get-files-by-prefix-no-bucket-service');
        }

        $fileDatas = $this->blobService->getFileDataByBucketIDAndPrefixWithPagination($bucketId, $prefix, $currentPageNumber, $maxNumItemsPerPage);

        //create sharelinks
        foreach ($fileDatas as $fileData) {
            assert($fileData instanceof FileData);
            $fileData->setBucket($bucket);
            $fileData = $this->blobService->getLink($fileData);
//            $this->blobService->saveFileData($fileData);
        }

        return $fileDatas;
    }

    /**
     * Check dbp-signature on GET request.
     *
     * @throws \JsonException
     */
    private function checkSignature(string $secret, array $filters): void
    {
        $sig = $this->requestStack->getCurrentRequest()->headers->get('x-dbp-signature', '');
        if (!$sig) {
            throw ApiError::withDetails(Response::HTTP_UNAUTHORIZED, 'Signature missing', 'blob:createFileData-missing-sig');
        }
        $bucketId = $filters['bucketID'] ?? '';
        $creationTime = $filters['creationTime'] ?? '0';

        if (!$bucketId || !$creationTime) {
            throw ApiError::withDetails(Response::HTTP_FORBIDDEN, 'Signature parameter missing', 'blob:dataprovider-missing-signature-params');
        }

        $data = DenyAccessUnlessCheckSignature::verify($secret, $sig);
//        dump($data);

        // check if signed params aer equal to request params
        if ($data['bucketID'] !== $bucketId) {
            /** @noinspection ForgottenDebugOutputInspection */
            dump($data['bucketID'], $bucketId);
            throw ApiError::withDetails(Response::HTTP_FORBIDDEN, 'BucketId change forbidden', 'blob:bucketid-change-forbidden');
        }
        if ((int) $data['creationTime'] !== (int) $creationTime) {
            /** @noinspection ForgottenDebugOutputInspection */
            dump($data['creationTime'], $creationTime);
            throw ApiError::withDetails(Response::HTTP_FORBIDDEN, 'Creation Time change forbidden', 'blob:creationtime-change-forbidden');
        }
        // TODO check if request is NOT too old
    }
}
