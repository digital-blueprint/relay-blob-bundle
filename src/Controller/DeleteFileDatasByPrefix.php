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

    /**
     * @throws \JsonException
     */
    public function __invoke(Request $request)
    {
        $errorPrefix = 'blob:delete-file-data-by-prefix';
        DenyAccessUnlessCheckSignature::checkMinimalParameters($errorPrefix, $this->blobService, $request, [], ['DELETE']);

        // get remaining required params, check type and decode according to RFC3986
        $prefix = $request->query->get('prefix');

        // check if the minimal required params are present
        if ($prefix === null) {
            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'Signature cannot be checked', 'blob:delete-file-data-by-prefix-prefix-missing');
        }

        assert(is_string($prefix));
        $prefix = rawurldecode($prefix);

        // verify signature and checksum
        $bucketID = $request->query->get('bucketID', '');
        assert(is_string($bucketID));
        $startsWith = $request->query->get('startsWith', '');
        assert(is_string($startsWith));
        $bucketID = rawurldecode($bucketID);

        $secret = $this->blobService->getSecretOfBucketWithBucketID($bucketID);
        DenyAccessUnlessCheckSignature::checkSignature($secret, $request, $this->blobService);

        // now, after checksum and signature check, it is safe to do stuff

        // get all the file datas associated with the prefix, and decide whether the prefix should be used as 'startsWith'
        if ($startsWith) {
            $fileDatas = $this->blobService->getFileDataByBucketIDAndStartsWithPrefix($bucketID, $prefix);
        } else {
            $fileDatas = $this->blobService->getFileDataByBucketIDAndPrefix($bucketID, $prefix);
        }

        // remove all the files datas
        foreach ($fileDatas as $fileData) {
            $bucket = $this->blobService->configurationService->getBucketByID($bucketID);
            $fileData->setBucket($bucket);
            $this->blobService->removeFileData($fileData);
        }
    }
}
