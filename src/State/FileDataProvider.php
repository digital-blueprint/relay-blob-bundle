<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\State;

use Dbp\Relay\BlobBundle\Entity\FileData;
use Dbp\Relay\BlobBundle\Helper\DenyAccessUnlessCheckSignature;
use Dbp\Relay\BlobBundle\Service\BlobService;
use Dbp\Relay\CoreBundle\Exception\ApiError;
use Dbp\Relay\CoreBundle\Rest\AbstractDataProvider;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;

class FileDataProvider extends AbstractDataProvider
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
        parent::__construct();
        $this->blobService = $blobService;
        $this->requestStack = $requestStack;
    }

    protected function isUserGrantedOperationAccess(int $operation): bool
    {
        return true;
    }

    protected function getItemById(string $id, array $filters = [], array $options = []): ?object
    {
        return $this->getFileDataById($id, $filters);
    }

    protected function getFileDataById($id, array $filters): object
    {
        $sig = $this->requestStack->getCurrentRequest()->query->get('sig', '');
        assert(is_string($sig));
        if (!$sig) {
            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'Signature missing', 'blob:get-file-data-by-id-missing-sig');
        }

        $bucketId = $filters['bucketID'] ?? '';
        assert(is_string($bucketId));
        if (!$bucketId) {
            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'BucketID is missing', 'blob:get-file-data-by-id-missing-bucket-id');
        }

        $urlMethod = $filters['method'] ?? '';
        assert(is_string($urlMethod));
        if (!$urlMethod) {
            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'method is missing', 'blob:get-file-data-by-id-missing-bucket-id');
        }

        $bucket = $this->blobService->configurationService->getBucketByID($bucketId);
        if (!$bucket) {
            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'Bucket is not configured', 'blob:get-file-data-by-id-bucket-not-configured');
        }

        $method = $this->requestStack->getCurrentRequest()->getMethod();
        $urlMethod = $filters['method'] ?? '';
        assert(is_string($urlMethod));

        if (($method === 'GET' && $urlMethod !== 'GET') || ($method === 'DELETE' && $urlMethod !== 'DELETE')) {
            throw ApiError::withDetails(Response::HTTP_METHOD_NOT_ALLOWED, 'Action/Method combination is wrong', 'blob:get-file-data-by-id-method-not-suitable');
        }

        // get secret of bucket
        $secret = $bucket->getKey();

        // check if signature is valid
        $this->checkSignature($secret, $filters);

        /** @var FileData $fileData */
        $fileData = $this->blobService->getFileData($id);

        // check if filedata is null
        assert(!is_null($fileData));

        if (!$fileData) {
            throw ApiError::withDetails(Response::HTTP_NOT_FOUND, 'FileData was not found!', 'blob:file-data-not-found');
        }

        //$fileData = $this->blobService->setBucket($fileData);
        if ($this->requestStack->getCurrentRequest()->getMethod() !== 'DELETE') {
            // create shareLink
            /** @var FileData $fileData */
            $fileData = $this->blobService->getLink($fileData);

            // check if filedata is null
            assert(!is_null($fileData));

            // check if PUT request was used
            if ($this->requestStack->getCurrentRequest()->getMethod() === 'PUT') {
                /** @var string */
                $fileName = $this->requestStack->getCurrentRequest()->query->get('fileName', '');
                assert(is_string($fileName));
                $fileData->setFileName($fileName);
                $this->blobService->saveFileData($fileData);
            }

            // check if GET request was used
            if ($this->requestStack->getCurrentRequest()->getMethod() === 'GET') {
                // check if includeData parameter is set
                /** @var string */
                $includeData = $this->requestStack->getCurrentRequest()->query->get('includeData', '');
                if ($includeData && $includeData === '1') {
                    $fileData = $this->blobService->getBase64Data($fileData);
                    //$response = new RedirectResponse($fileData->getContentUrl(), 302);
                    //return $response;
                }
            }
        }

        return $fileData;
    }

    /**
     * @throws \JsonException
     */
    protected function getPage(int $currentPageNumber, int $maxNumItemsPerPage, array $filters = [], array $options = []): array
    {
        // check if signature is present
        $sig = $this->requestStack->getCurrentRequest()->query->get('sig', '');
        $baseUrl = $this->requestStack->getCurrentRequest()->getSchemeAndHttpHost();
        if (!$sig) {
            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'Signature missing', 'blob:get-file-data-collection-missing-sig');
        }
        $bucketId = $filters['bucketID'] ?? '';
        $prefix = $filters['prefix'] ?? '';
        $creationTime = $filters['creationTime'] ?? '';
        $urlMethod = $filters['method'] ?? '';
        assert(is_string($bucketId));
        assert(is_string($prefix));
        assert(is_string($creationTime));
        assert(is_string($urlMethod));

        /** @var string $bucketId */
        $bucketId = $this->requestStack->getCurrentRequest()->query->get('bucketID', '');

        // check if bucketID is present
        if (!$bucketId) {
            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'BucketID is missing', 'blob:get-file-data-collection-missing-bucket-id');
        }
        // check if prefix is present
        if (!$prefix) {
            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'prefix is missing', 'blob:get-file-data-collection-missing-prefix');
        }
        if (!$creationTime) {
            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'creationTime is missing', 'blob:get-file-data-collection-missing-creation-time');
        }
        if (!$urlMethod) {
            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'method is missing', 'blob:get-file-data-collection-missing-method');
        }

        // check if bucketID is correct
        $bucket = $this->blobService->configurationService->getBucketByID($bucketId);
        if (!$bucket) {
            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'BucketID is not configured', 'blob:get-file-data-collection-bucket-id-not-configured');
        }

        // check if signature and checksum is correct
        $secret = $bucket->getKey();
        $this->checkSignature($secret, $filters);

        $includeData = $filters['includeData'] ?? '';
        assert(is_string($bucketId));

        if (!$bucket->getService()) {
            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'BucketService is not configured', 'blob:get-file-data-collection-no-bucket-service');
        }

        // get file data of bucket for current page
        $fileDatas = $this->blobService->getFileDataByBucketIDAndPrefixWithPagination($bucketId, $prefix, $currentPageNumber, $maxNumItemsPerPage);

        // create sharelinks
        foreach ($fileDatas as &$fileData) {
            assert($fileData instanceof FileData);
            $fileData->setBucket($bucket);
            $fileData = $this->blobService->getLink($fileData);

            $fileData->setContentUrl($this->blobService->generateGETLink($baseUrl, $fileData, $includeData));
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
            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'Signature missing', 'blob:check-signature-missing-sig');
        }

        $bucketId = $filters['bucketID'] ?? '';
        $creationTime = $filters['creationTime'] ?? '0';
        $urlMethod = $filters['method'] ?? '';

        // check if the minimal params are present
        if (!$bucketId || !$creationTime || !$urlMethod) {
            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'bucketID, creationTime or method parameter missing', 'blob:check-signature-missing-signature-params');
        }

        // verify signature and checksum
        DenyAccessUnlessCheckSignature::verifyChecksumAndSignature($secret, $sig, $this->requestStack->getCurrentRequest());

        // now, after the signature and checksum check it is safe to something

        $bucket = $this->blobService->configurationService->getBucketByID($bucketId);
        $linkExpiryTime = $bucket->getLinkExpireTime();
        $now = new \DateTime('now');
        $now->sub(new \DateInterval($linkExpiryTime));
        $expiryTime = strtotime($now->format('c'));

        // check if request is expired
        if ((int) $creationTime < $expiryTime || $expiryTime === false) {
            throw ApiError::withDetails(Response::HTTP_FORBIDDEN, 'Parameter creationTime too old', 'blob:check-signature-creation-time-too-old');
        }

        // check action/method
        $method = $this->requestStack->getCurrentRequest()->getMethod();

        // check if the provided method and action is suitable
        if (($method === 'GET' && $urlMethod !== 'GET' && $urlMethod !== 'GET')
            || ($method === 'DELETE' && $urlMethod !== 'DELETE' && $urlMethod !== 'DELETE')
            || ($method === 'PUT' && $urlMethod !== 'PUT')
            || ($method === 'POST' && $urlMethod !== 'POST')
        ) {
            throw ApiError::withDetails(Response::HTTP_METHOD_NOT_ALLOWED, 'Method and/or action not suitable', 'blob:check-signature-method-not-suitable');
        }
    }
}
