<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\DataProvider;

use Dbp\Relay\BlobBundle\Entity\FileData;
use Dbp\Relay\BlobBundle\Helper\DenyAccessUnlessCheckSignature;
use Dbp\Relay\BlobBundle\Service\BlobService;
use Dbp\Relay\CoreBundle\DataProvider\AbstractDataProvider;
use Dbp\Relay\CoreBundle\Exception\ApiError;
use function PHPUnit\Framework\assertNotNull;
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
        $this->blobService = $blobService;
        $this->requestStack = $requestStack;
    }

    protected function getResourceClass(): string
    {
        return FileData::class;
    }

    protected function isUserGrantedOperationAccess(int $operation): bool
    {
        return true;
    }

    protected function getItemById($id, array $filters = [], array $options = []): object
    {
        return $this->getFileDataById($id, $filters);
    }

    protected function getFileDataById($id, array $filters): object
    {
//        echo "     FileDataProvider::getFileDataById($id, filters)\n";
        $sig = $this->requestStack->getCurrentRequest()->query->get('sig', '');
        // dump($sig);
        if (!$sig) {
            // TODO remove signature from header. For now, it is supported in both url and header
            $sig = $this->requestStack->getCurrentRequest()->headers->get('x-dbp-signature', '');
            if (!$sig) {
                throw ApiError::withDetails(Response::HTTP_UNAUTHORIZED, 'Signature missing', 'blob:createFileData-missing-sig');
            }
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
            // create shareLink
            $fileData = $this->blobService->getLink($fileData);
            $this->blobService->saveFileData($fileData);
        }

        return $fileData;
    }

    protected function getPage(int $currentPageNumber, int $maxNumItemsPerPage, array $filters = [], array $options = []): array
    {
        $sig = $this->requestStack->getCurrentRequest()->query->get('sig', '');
        // dump($sig);
        if (!$sig) {
            // TODO remove signature from header. For now, it is supported in both url and header
            $sig = $this->requestStack->getCurrentRequest()->headers->get('x-dbp-signature', '');
            if (!$sig) {
                throw ApiError::withDetails(Response::HTTP_UNAUTHORIZED, 'Signature missing', 'blob:createFileData-missing-sig');
            }
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

        assertNotNull($this->blobService, 'blob service not initialized');
        // create sharelinks
        foreach ($fileDatas as &$fileData) {
            assert($fileData instanceof FileData);
            $fileData->setBucket($bucket);
            $fileData = $this->blobService->getLink($fileData);
            $this->blobService->saveFileData($fileData);
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
        $sig = $this->requestStack->getCurrentRequest()->query->get('sig', '');
        // dump($sig);
        if (!$sig) {
            // TODO remove signature from header. For now, it is supported in both url and header
            $sig = $this->requestStack->getCurrentRequest()->headers->get('x-dbp-signature', '');
            if (!$sig) {
                throw ApiError::withDetails(Response::HTTP_UNAUTHORIZED, 'Signature missing', 'blob:createFileData-missing-sig');
            }
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
            /* @noinspection ForgottenDebugOutputInspection */
            // dump($data['bucketID'], $bucketId);
            throw ApiError::withDetails(Response::HTTP_FORBIDDEN, 'BucketId change forbidden', 'blob:bucketid-change-forbidden');
        }
        if ((int) $data['creationTime'] !== (int) $creationTime) {
            /* @noinspection ForgottenDebugOutputInspection */
            //dump($data['creationTime'], $creationTime);
            throw ApiError::withDetails(Response::HTTP_FORBIDDEN, 'Creation Time change forbidden', 'blob:creationtime-change-forbidden');
        }
        // check if request is expired
        if ((int) $data['creationTime'] < $tooOld = strtotime('-5 min')) {
            /* @noinspection ForgottenDebugOutputInspection */
            // dump((int) $data['creationTime'], $tooOld);
            throw ApiError::withDetails(Response::HTTP_FORBIDDEN, 'Creation Time too old', 'blob:creationtime-too-old');
        }
        // check action/method
        $method = $this->requestStack->getCurrentRequest()->getMethod();
        $action = $data['action'] ?? '';
        //echo "    FileDataProvider::checkSignature(): method=$method, action=$action\n";

        // dump($method);
        if (($method === 'GET' && $action !== 'GETONE' && $action !== 'GETALL')
            || ($method === 'DELETE' && $action !== 'DELETEONE' && $action !== 'DELETEALL')
            || ($method === 'POST' && $action !== 'CREATEONE')
        ) {
            throw ApiError::withDetails(Response::HTTP_FORBIDDEN, 'Signature not suitable', 'blob:dataprovider-signature-not-suitable');
        }
    }
}
