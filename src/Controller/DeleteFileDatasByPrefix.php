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
        $creationTime = $request->query->get('creationTime', '');
        $prefix = $request->query->get('prefix', '');

        if (!$bucketId || !$creationTime || !$prefix) {
            throw ApiError::withDetails(Response::HTTP_FORBIDDEN, 'Signature cannot checked', 'blob:delete-files-per-prefix-unset-sig-params');
        }

        $bucket = $this->blobService->configurationService->getBucketByID($bucketId);
        if (!$bucket) {
            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'BucketID is not configured', 'blob:get-files-by-prefix-not-configured-bucketID');
        }

        $secret = $bucket->getPublicKey();
        $data = DenyAccessUnlessCheckSignature::verify($secret, $sig);
        dump($data);

        // check if signed params are equal to request params
        if ($data['bucketID'] !== $bucketId) {
            throw ApiError::withDetails(Response::HTTP_FORBIDDEN, 'BucketId change forbidden', 'blob:bucketid-change-forbidden');
        }
        if ((int)$data['creationTime'] !== (int)$creationTime) {
            throw ApiError::withDetails(Response::HTTP_FORBIDDEN, 'Creation Time change forbidden', 'blob:creationtime-change-forbidden');
        }
        if ($data['prefix'] !== $prefix) {
            throw ApiError::withDetails(Response::HTTP_FORBIDDEN, 'Prefix change forbidden', 'blob:prefix-change-forbidden');
        }
        // TODO check if request is NOT too old

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
