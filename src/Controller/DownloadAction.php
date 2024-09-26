<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Controller;

use Dbp\Relay\BlobBundle\Helper\DenyAccessUnlessCheckSignature;
use Dbp\Relay\BlobBundle\Service\BlobService;
use Dbp\Relay\CoreBundle\Exception\ApiError;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

class DownloadAction extends AbstractController
{
    public function __construct(private readonly BlobService $blobService)
    {
    }

    /**
     * @throws \JsonException
     * @throws \Exception
     */
    public function __invoke(Request $request, string $identifier): Response
    {
        if (!Uuid::isValid($identifier)) {
            throw ApiError::withDetails(Response::HTTP_NOT_FOUND, 'Identifier is in an invalid format!', 'blob:identifier-invalid-format');
        }

        $errorPrefix = 'blob:download-file-by-id';
        DenyAccessUnlessCheckSignature::checkSignature($errorPrefix, $this->blobService, $request, $request->query->all(),
            ['GET'], $this->isGranted('IS_AUTHENTICATED_FULLY'), $this->blobService->checkAdditionalAuth());

        // check if identifier is given
        if (!$identifier) {
            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'No identifier set', $errorPrefix.'-missing-identifier');
        }

        // get data associated with the provided identifier
        $fileData = $this->blobService->getFileData($identifier);
        // check if fileData is null
        if ($fileData->getDeleteAt() !== null && $fileData->getDeleteAt() < new \DateTimeImmutable()) {
            throw ApiError::withDetails(Response::HTTP_NOT_FOUND, 'FileData was not found!', 'blob:file-data-not-found');
        }

        $bucket = $this->blobService->ensureBucket($fileData);

        $disableValidation = $request->get('disableOutputValidation', '');
        if (!($disableValidation === '1')) {
            $this->blobService->checkFileDataBeforeRetrieval($fileData, $bucket, $errorPrefix);
        }

        return $this->blobService->getBinaryResponse($fileData);
    }
}
