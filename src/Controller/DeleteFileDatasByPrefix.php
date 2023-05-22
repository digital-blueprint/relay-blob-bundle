<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Controller;

use Dbp\Relay\BlobBundle\Helper\DenyAccessUnlessCheckSignature;
use Dbp\Relay\BlobBundle\Service\BlobService;
use Dbp\Relay\CoreBundle\Exception\ApiError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class DeleteFileDatasByPrefix extends BaseBlobController
{
    /**
     * @var BlobService
     */
    private $blobService;

    public function __construct(BlobService $blobService)
    {
        $this->blobService = $blobService;
    }

    public function __invoke(Request $request)
    {
        $sig = $request->headers->get('x-dbp-signature', '');
        if (!$sig) {
            throw ApiError::withDetails(Response::HTTP_UNAUTHORIZED, 'Signature missing', 'blob:createFileData-missing-sig');
        }

        $bucketId = $request->query->get('bucketID', '');
        assert(is_string($bucketId));
        $creationTime = $request->query->get('creationTime', 0);
        $prefix = $request->query->get('prefix', '');
        assert(is_string($prefix));

        if (!$bucketId || !$creationTime || !$prefix) {
            throw ApiError::withDetails(Response::HTTP_FORBIDDEN, 'Signature cannot checked', 'blob:delete-files-per-prefix-unset-sig-params');
        }

        $bucket = $this->blobService->configurationService->getBucketByID($bucketId);
        if (!$bucket) {
            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'BucketID is not configured', 'blob:get-files-by-prefix-not-configured-bucketID');
        }

        $secret = $bucket->getPublicKey();
        $data = DenyAccessUnlessCheckSignature::verify($secret, $sig);


        // check if signed params are equal to request params
        if ($data['bucketID'] !== $bucketId) {
            throw ApiError::withDetails(Response::HTTP_FORBIDDEN, 'BucketId change forbidden', 'blob:bucketid-change-forbidden');
        }
        if ((int) $data['creationTime'] !== (int) $creationTime) {
            throw ApiError::withDetails(Response::HTTP_FORBIDDEN, 'Creation Time change forbidden', 'blob:creationtime-change-forbidden');
        }
        if ($data['prefix'] !== $prefix) {
            throw ApiError::withDetails(Response::HTTP_FORBIDDEN, 'Prefix change forbidden', 'blob:prefix-change-forbidden');
        }
        // check if request is expired
        if ((int) $data['creationTime'] < $tooOld = strtotime('-5 min')) {
            throw ApiError::withDetails(Response::HTTP_FORBIDDEN, 'Creation Time too old', 'blob:creationtime-too-old');
        }
        // check action/method
        $method = $request->getMethod();
        $action = $data['action'] ?? '';
        //echo "    DeleteFileDataByPrefix::__invoke(): method=$method, action=$action\n";
        if (($method !== 'DELETE' || $action !== 'DELETEALL')) {
            throw ApiError::withDetails(Response::HTTP_FORBIDDEN, 'Signature not suitable', 'blob:dataprovider-signature-not-suitable');
        }

        if (!$bucket->getService()) {
            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'BucketService is not configured', 'blob:get-files-by-prefix-no-bucket-service');
        }

        $fileDatas = $this->blobService->getFileDataByBucketIDAndPrefix($bucketId, $prefix);

        //remove files
        foreach ($fileDatas as $fileData) {
            $fileData->setBucket($bucket);
            $this->blobService->removeFileData($fileData);
        }
    }
}
