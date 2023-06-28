<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\DataProvider;

use Dbp\Relay\BlobBundle\Entity\FileData;
use Dbp\Relay\BlobBundle\Helper\DenyAccessUnlessCheckSignature;
use Dbp\Relay\BlobBundle\Service\BlobService;
use Dbp\Relay\CoreBundle\DataProvider\AbstractDataProvider;
use Dbp\Relay\CoreBundle\Exception\ApiError;
use Symfony\Component\HttpFoundation\RedirectResponse;
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
        $sig = $this->requestStack->getCurrentRequest()->query->get('sig', '');
        assert(is_string($sig));
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

        // get secret of bucket
        $secret = $bucket->getPublicKey();

        // check if signature is valid
        $this->checkSignature($secret, $filters);

        /** @var FileData $fileData */
        $fileData = $this->blobService->getFileData($id);

        // check if filedata is null
        assert(!is_null($fileData));

        if (!$fileData) {
            throw ApiError::withDetails(Response::HTTP_NOT_FOUND, 'FileData was not found!', 'blob:fileData-not-found');
        }

        //$fileData = $this->blobService->setBucket($fileData);
        if ($this->requestStack->getCurrentRequest()->getMethod() !== 'DELETE') {
            // create shareLink
            /** @var FileData $fileData */
            $fileData = $this->blobService->getLink($fileData);

            // check if filedata is null
            assert(!is_null($fileData));

            // check if put request
            if ($this->requestStack->getCurrentRequest()->getMethod() === 'PUT') {
                /** @var string */
                $fileName = $this->requestStack->getCurrentRequest()->query->get('fileName', '');
                assert(is_string($fileName));
                $fileData->setFileName($fileName);
                $this->blobService->saveFileData($fileData);
            }

            // check if get request
            if ($this->requestStack->getCurrentRequest()->getMethod() === 'GET') {
                // check if binary parameter is set
                /** @var string */
                $binary = $this->requestStack->getCurrentRequest()->query->get('binary', '');
                if ($binary && $binary === '1') {
                    $response = new RedirectResponse($fileData->getContentUrl(), 302);

                    return $response;
                }
            }
        }

        return $fileData;
    }

    protected function getPage(int $currentPageNumber, int $maxNumItemsPerPage, array $filters = [], array $options = []): array
    {
        // check if signature is presennt
        $sig = $this->requestStack->getCurrentRequest()->query->get('sig', '');
        if (!$sig) {
            throw ApiError::withDetails(Response::HTTP_UNAUTHORIZED, 'Signature missing', 'blob:createFileData-missing-sig');
        }
        $bucketId = $filters['bucketID'] ?? '';
        $prefix = $filters['prefix'] ?? '';
        assert(is_string($bucketId));

        // check if bucketID is present
        if (!$bucketId) {
            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'BucketID is missing', 'blob:get-files-by-prefix-missing-bucketID');
        }

        // check if bucketID is correct
        $bucket = $this->blobService->configurationService->getBucketByID($bucketId);
        if (!$bucket) {
            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'BucketID is not configured', 'blob:get-files-by-prefix-not-configured-bucketID');
        }

        // check if signature and checksum is correct
        $secret = $bucket->getPublicKey();
        $this->checkSignature($secret, $filters);

        $binary = $filters['binary'] ?? '';
        assert(is_string($bucketId));

        if (!$bucket->getService()) {
            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'BucketService is not configured', 'blob:get-files-by-prefix-no-bucket-service');
        }

        // get file data of bucket for current page
        $fileDatas = $this->blobService->getFileDataByBucketIDAndPrefixWithPagination($bucketId, $prefix, $currentPageNumber, $maxNumItemsPerPage);

        // create sharelinks
        foreach ($fileDatas as &$fileData) {
            assert($fileData instanceof FileData);
            $fileData->setBucket($bucket);
            $fileData = $this->blobService->getLink($fileData);

            $fileData->setContentUrl($this->blobService->generateGETONELink($fileData, $binary));
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
        // check if signature is present
        /** @var string */
        $sig = $this->requestStack->getCurrentRequest()->query->get('sig', '');
        if (!$sig) {
            throw ApiError::withDetails(Response::HTTP_UNAUTHORIZED, 'Signature missing', 'blob:createFileData-missing-sig');
        }
        $bucketId = $filters['bucketID'] ?? '';
        $creationTime = $filters['creationTime'] ?? '0';
        $action = $filters['action'] ?? '';

        // check if the minimal params are present
        if (!$bucketId || !$creationTime || !$action) {
            throw ApiError::withDetails(Response::HTTP_FORBIDDEN, 'Signature parameter missing', 'blob:dataprovider-missing-signature-params');
        }

        // verify signature and checksum
        DenyAccessUnlessCheckSignature::verifyChecksumAndSignature($secret, $sig, $this->requestStack->getCurrentRequest());

        // now, after the signature and checksum check it is safe to to something

        // check if request is expired
        if ((int) $creationTime < $tooOld = strtotime('-5 min')) {
            throw ApiError::withDetails(Response::HTTP_FORBIDDEN, 'Creation Time too old', 'blob:creationtime-too-old');
        }

        // check action/method
        $method = $this->requestStack->getCurrentRequest()->getMethod();

        // check if the provided method and action is suitable
        if (($method === 'GET' && $action !== 'GETONE' && $action !== 'GETALL')
            || ($method === 'DELETE' && $action !== 'DELETEONE' && $action !== 'DELETEALL')
            || ($method === 'POST' && $action !== 'CREATEONE')
        ) {
            throw ApiError::withDetails(Response::HTTP_FORBIDDEN, 'Signature not suitable', 'blob:dataprovider-signature-not-suitable');
        }
    }
}
