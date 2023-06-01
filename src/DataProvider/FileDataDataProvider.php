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
        //$this->checkChecksum($secret, $filters, $id);
        $this->checkSignature($secret, $filters);

        $fileData = $this->blobService->getFileData($id);

        if (!$fileData) {
            throw ApiError::withDetails(Response::HTTP_NOT_FOUND, 'FileData was not found!', 'blob:fileData-not-found');
        }

        //$fileData = $this->blobService->setBucket($fileData);
        if ($this->requestStack->getCurrentRequest()->getMethod() !== 'DELETE') {
            // create shareLink
            $fileData = $this->blobService->getLink($fileData);

            if ($this->requestStack->getCurrentRequest()->getMethod() === 'PUT') {
                $fileData->setFileName($this->requestStack->getCurrentRequest()->query->get('fileName', ''));
                $this->blobService->saveFileData($fileData);
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

            //$this->blobService->saveFileData($fileData);
        }

        return $fileDatas;
    }

    /**
     * Check dbp-checksum on GET request.
     *
     * @throws \JsonException
     */
    private function checkChecksum(string $secret, array $filters, string $id=''): void
    {
        /** @var string */
        $cs = $this->requestStack->getCurrentRequest()->query->get('checksum', '');

        if (!$cs) {
            throw ApiError::withDetails(Response::HTTP_UNAUTHORIZED, 'Signature missing', 'blob:createFileData-missing-sig');
        }
        $bucketId = $filters['bucketID'] ?? '';
        $creationTime = $filters['creationTime'] ?? '0';

        if (!$bucketId || !$creationTime) {
            throw ApiError::withDetails(Response::HTTP_FORBIDDEN, 'Signature parameter missing', 'blob:dataprovider-missing-signature-params');
        }
        $prefix = $this->requestStack->getCurrentRequest()->query->get('prefix', '');
        $action = $this->requestStack->getCurrentRequest()->query->get('action', '');
        $hash = $this->generateChecksum($this->requestStack->getCurrentRequest()->getPathInfo(), $bucketId, $creationTime, $prefix, $action, $secret, $id);

        // check if checksum is correct
        if ($cs !== $hash) {
            throw ApiError::withDetails(Response::HTTP_FORBIDDEN, 'Checksum is not correct', 'blob:dataprovider-signature-not-suitable');
        }
        // check if signed params aer equal to request params
        if ($this->requestStack->getCurrentRequest()->query->get('bucketID', '') !== $bucketId) {
            /* @noinspection ForgottenDebugOutputInspection */
            // dump($data['bucketID'], $bucketId);
            throw ApiError::withDetails(Response::HTTP_FORBIDDEN, 'BucketId change forbidden', 'blob:bucketid-change-forbidden');
        }
        if ((int) $this->requestStack->getCurrentRequest()->query->get('creationTime', '') !== (int) $creationTime) {
            /* @noinspection ForgottenDebugOutputInspection */
            //dump($data['creationTime'], $creationTime);
            throw ApiError::withDetails(Response::HTTP_FORBIDDEN, 'Creation Time change forbidden', 'blob:creationtime-change-forbidden');
        }
        // check if request is expired
        if ((int) $this->requestStack->getCurrentRequest()->query->get('creationTime', '') < $tooOld = strtotime('-5 min')) {
            /* @noinspection ForgottenDebugOutputInspection */
            // dump((int) $data['creationTime'], $tooOld);
            throw ApiError::withDetails(Response::HTTP_FORBIDDEN, 'Creation Time too old', 'blob:creationtime-too-old');
        }
        // check action/method
        $method = $this->requestStack->getCurrentRequest()->getMethod();
        $action = $this->requestStack->getCurrentRequest()->query->get('action', '');
        //echo "    FileDataProvider::checkSignature(): method=$method, action=$action\n";

        if (($method === 'GET' && $action !== 'GETONE' && $action !== 'GETALL')
            || ($method === 'DELETE' && $action !== 'DELETEONE' && $action !== 'DELETEALL')
            || ($method === 'POST' && $action !== 'CREATEONE')
        ) {
            throw ApiError::withDetails(Response::HTTP_FORBIDDEN, 'Signature not suitable', 'blob:dataprovider-signature-not-suitable');
        }
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

    private function generateChecksum($pathInfo, $bucketId, $creationTime, $prefix, $action, $secret, $id=''): string
    {
        $url = $pathInfo.'?bucketID='.$bucketId.'&creationTime='.$creationTime.'&prefix='.$prefix.'&action='.$action;
        return hash_hmac('sha256', $url, $secret);
    }
}
