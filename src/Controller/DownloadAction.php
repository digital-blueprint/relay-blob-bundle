<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Controller;

use Dbp\Relay\BlobBundle\Helper\DenyAccessUnlessCheckSignature;
use Dbp\Relay\BlobBundle\Service\BlobService;
use Dbp\Relay\CoreBundle\Exception\ApiError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

class DownloadAction extends BaseBlobController
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
    public function __invoke(Request $request, string $identifier): Response
    {
        if (!Uuid::isValid($identifier)) {
            throw ApiError::withDetails(Response::HTTP_NOT_FOUND, 'Identifier is in an invalid format!', 'blob:identifier-invalid-format');
        }

        $errorPrefix = 'blob:download-file-by-id';
        DenyAccessUnlessCheckSignature::checkMinimalParameters($errorPrefix, $this->blobService, $request, [], ['GET']);
        // check if identifier is given
        if (!$identifier) {
            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'No identifier set', $errorPrefix.'-missing-identifier');
        }

        $bucketID = rawurldecode($request->get('bucketIdentifier', ''));
        $secret = $this->blobService->getSecretOfBucketWithBucketID($bucketID);

        // check if the signature is valid
        DenyAccessUnlessCheckSignature::checkSignature($secret, $request, $this->blobService, $this->isGranted('IS_AUTHENTICATED_FULLY'), $this->blobService->checkAdditionalAuth());

        $urlMethod = rawurldecode($request->get('method', ''));
        $method = $request->getMethod();
        if ($method !== 'GET' || $urlMethod !== 'GET') {
            throw ApiError::withDetails(Response::HTTP_METHOD_NOT_ALLOWED, 'action/method is invalid', $errorPrefix.'-invalid-method');
        }

        // get data associated with the provided identifier
        $fileData = $this->blobService->getFileData($identifier);

        // check if fileData is null
        if (!$fileData || ($fileData->getExistsUntil() !== null && $fileData->getExistsUntil() < new \DateTimeImmutable()) || ($fileData->getExistsUntil() === null && $fileData->getDateCreated()->add(new \DateInterval($this->blobService->getBucketByID($bucketID)->getMaxRetentionDuration())) < new \DateTimeImmutable())) {
            throw ApiError::withDetails(Response::HTTP_NOT_FOUND, 'FileData was not found!', 'blob:file-data-not-found');
        }

        $this->blobService->setBucket($fileData);

        $disableValidation = $request->get('disableOutputValidation', '');
        if (!($disableValidation === '1')) {
            $this->blobService->checkFileDataBeforeRetrieval($fileData, $bucketID, $errorPrefix);
        }

        return $this->blobService->getBinaryResponse($fileData);
    }
}
