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
        // check if signature is present
        /** @var string $sig */
        $sig = $request->query->get('sig', '');
        if (!$sig) {
            throw ApiError::withDetails(Response::HTTP_UNAUTHORIZED, 'Signature missing', 'blob:deleteFileDataByPrefix-missing-sig');
        }
        // get params
        // get required params
        $bucketId = $request->query->get('bucketID', '');
        $creationTime = $request->query->get('creationTime', 0);
        $prefix = $request->query->get('prefix', '');
        $action = $request->query->get('action', '');
        assert(is_string($bucketId));
        assert(is_string($prefix));
        assert(is_string($sig));

        // check if the minimal required params are present
        if (!$bucketId || !$creationTime || !$prefix || !$action) {
            throw ApiError::withDetails(Response::HTTP_FORBIDDEN, 'Signature cannot be checked', 'blob:deleteFileDataByPrefix-unset-sig-params');
        }

        // check if bucketID is correct
        $bucket = $this->blobService->configurationService->getBucketByID($bucketId);
        if (!$bucket) {
            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'BucketID is not configured', 'blob:deleteFileDataByPrefix-bucketID-not-configured');
        }

        // check if request is expired
        if ((int) $creationTime < strtotime('-5 min')) {
            throw ApiError::withDetails(Response::HTTP_FORBIDDEN, 'Creation Time too old', 'blob:deleteFileDataByPrefix-creationtime-too-old');
        }

        // check action/method
        $method = $request->getMethod();
        if (($method !== 'DELETE' || $action !== 'DELETEALL')) {
            throw ApiError::withDetails(Response::HTTP_METHOD_NOT_ALLOWED, 'Method and/or action not suitable', 'blob:deleteFileDataByPrefix-method-not-suitable');
        }

        // verify signature and checksum
        $secret = $bucket->getKey();
        DenyAccessUnlessCheckSignature::verifyChecksumAndSignature($secret, $sig, $request);

        // now, after checksum and signature check, it is safe to do stuff

        if (!$bucket->getService()) {
            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'BucketService is not configured', 'blob:deleteFileDataByPrefix-no-bucket-service');
        }

        $fileDatas = $this->blobService->getFileDataByBucketIDAndPrefix($bucketId, $prefix);

        //remove files
        foreach ($fileDatas as $fileData) {
            $fileData->setBucket($bucket);
            $this->blobService->removeFileData($fileData);
        }
    }
}
