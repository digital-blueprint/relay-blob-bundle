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
        $bucketId = (string) $request->query->get('bucketID', '');
        $creationTime = (string) $request->query->get('creationTime', '');
        $uri = $request->getUri();
        $sig = $request->headers->get('x-dbp-signature', '');

        if (!$uri || !$sig || !$bucketId || !$creationTime) {
            throw ApiError::withDetails(Response::HTTP_FORBIDDEN, 'Signature cannot checked', 'blob:deleteFilesperprefix-unset-sig-params');
        }

        DenyAccessUnlessCheckSignature::denyAccessUnlessSiganture($bucketId, $creationTime, $uri, $sig);

        if (!$bucketId) {
            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'BucketID is not configured', 'blob:get-files-by-prefix-unset-bucketID');
        }
        $bucket = $this->blobService->configurationService->getBucketByID($bucketId);
        if (!$bucket) {
            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'BucketID is not configured', 'blob:get-files-by-prefix-not-configured-bucketID');
        }

        $prefix = (string) $request->query->get('prefix', '');

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
