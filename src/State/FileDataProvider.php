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

    /**
     * @throws \JsonException
     * @throws \Exception
     */
    protected function getFileDataById($id, array $filters): object
    {
        // check if the minimal needed parameters are present and correct
        $errorPrefix = 'blob:get-file-data-by-id';
        DenyAccessUnlessCheckSignature::checkMinimalParameters($errorPrefix, $this->blobService, $this->requestStack->getCurrentRequest(), $filters, ['GET', 'PUT', 'DELETE']);

        // get secret of bucket
        $bucketID = rawurldecode($filters['bucketID']) ?? '';
        $secret = $this->blobService->getSecretOfBucketWithBucketID($bucketID);

        // check if signature is valid
        DenyAccessUnlessCheckSignature::checkSignature($secret, $this->requestStack->getCurrentRequest(), $this->blobService);

        // get file data associated with the given identifier
        $fileData = $this->blobService->getFileData($id);

        // check if fileData is null
        if (!$fileData) {
            throw ApiError::withDetails(Response::HTTP_NOT_FOUND, 'FileData was not found!', 'blob:file-data-not-found');
        }

        // get used method of request
        $method = $this->requestStack->getCurrentRequest()->getMethod();

        // get the current time to save it as last access / last modified
        $time = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        // decide by method what to execute
        if ($method !== 'DELETE') {
            // create shareLink
            $fileData = $this->blobService->getLink($fileData);

            // check if filedata is null
            assert(!is_null($fileData));

            // check if PUT request was used
            if ($method === 'PUT') {
                $fileName = $filters['fileName'] ?? '';

                // throw error if filename is not provided
                if (!$fileName) {
                    throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'fileName is missing!', 'blob:put-file-data-missing-filename');
                }

                assert(is_string($fileName));
                $fileName = rawurldecode($fileName);
                $fileData->setFileName($fileName);
                $fileData->setDateModified($time);
            }
            // check if GET request was used
            elseif ($method === 'GET') {
                // check if includeData parameter is set
                $includeData = $filters['includeData'] ?? '';
                if ($includeData === '1') {
                    $fileData = $this->blobService->getBase64Data($fileData);
                }
            }
        }

        $this->blobService->saveFileData($fileData);

        return $fileData;
    }

    /**
     * @throws \JsonException
     * @throws \Exception
     */
    protected function getPage(int $currentPageNumber, int $maxNumItemsPerPage, array $filters = [], array $options = []): array
    {
        // set error prefix
        $errorPrefix = 'blob:get-file-data-collection';

        // check if minimal parameters for the request are present and valid
        DenyAccessUnlessCheckSignature::checkMinimalParameters($errorPrefix, $this->blobService, $this->requestStack->getCurrentRequest(), $filters, ['GET']);

        // get bucketID after check
        $bucketID = $filters['bucketID'] ?? '';

        // get prefix by filters
        $prefix = $filters['prefix'] ?? '';
        $prefix = rawurldecode($prefix);

        // check if signature and checksum is correct
        $secret = $this->blobService->getSecretOfBucketWithBucketID($bucketID);
        DenyAccessUnlessCheckSignature::checkSignature($secret, $this->requestStack->getCurrentRequest(), $this->blobService);

        // get includeData param and decode it
        $includeData = $filters['includeData'] ?? '';
        $startsWith = $filters['startsWith'] ?? '';
        assert(is_string($includeData));
        $includeData = rawurldecode($includeData) ?? '';

        // get file data of bucket for current page, and decide whether prefix should be used as 'startsWith' or not
        if ($startsWith) {
            $fileDatas = $this->blobService->getFileDataByBucketIDAndStartsWithPrefixWithPagination($bucketID, $prefix, $currentPageNumber, $maxNumItemsPerPage);
        } else {
            $fileDatas = $this->blobService->getFileDataByBucketIDAndPrefixWithPagination($bucketID, $prefix, $currentPageNumber, $maxNumItemsPerPage);
        }

        // create sharelinks
        foreach ($fileDatas as &$fileData) {
            assert($fileData instanceof FileData);
            $this->blobService->saveFileData($fileData);
            $fileData->setBucket($this->blobService->configurationService->getBucketByID($bucketID));
            $fileData = $this->blobService->getLink($fileData);
            $baseUrl = $this->requestStack->getCurrentRequest()->getSchemeAndHttpHost();
            $fileData->setContentUrl($this->blobService->generateGETLink($baseUrl, $fileData, $includeData));
        }

        return $fileDatas;
    }
}
