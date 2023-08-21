<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Controller;

use Dbp\Relay\BlobBundle\Helper\DenyAccessUnlessCheckSignature;
use Dbp\Relay\BlobBundle\Service\BlobService;
use Dbp\Relay\CoreBundle\Exception\ApiError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

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

    public function __invoke(Request $request, string $identifier): Response
    {
        if (!$identifier) {
            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'No identifier set', 'blob:download-file-by-id-missing-identifier');
        }

        $bucketId = $request->query->get('bucketID', '');
        assert(is_string($bucketId));
        $bucketId = rawurldecode($bucketId);
        if (!$bucketId) {
            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'bucketID is missing', 'blob:download-file-by-id-missing-bucket-id');
        }

        $urlMethod = $request->query->get('method', '');
        assert(is_string($urlMethod));
        $urlMethod = rawurldecode($urlMethod);
        if (!$urlMethod) {
            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'method is missing', 'blob:download-file-by-id-missing-method');
        }

        $creationTime = $request->query->get('creationTime', '');
        assert(is_string($creationTime));
        $creationTime = rawurldecode($creationTime);
        if (!$creationTime) {
            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'creationTime is missing', 'blob:download-file-by-id-missing-creation-time');
        }

        $bucket = $this->blobService->configurationService->getBucketByID($bucketId);
        if (!$bucket) {
            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'Bucket is not configured', 'blob:download-file-by-id-bucket-not-configured');
        }

        $method = $request->getMethod();
        if ($method !== 'GET' || $urlMethod !== 'GET') {
            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'action/method is invalid', 'blob:download-file-by-id-invalid-method');
        }

        // Check if sharelink is already invalid
        $fileData = $this->blobService->getFileData($identifier);
        $this->blobService->setBucket($fileData);

        /** @var string */
        $sig = $request->query->get('sig', '');

        if (!$sig) {
            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'Signature is missing', 'blob:download-file-by-id-missing-sig');
        }

        assert(is_string($sig));
        $sig = rawurldecode($sig);

        DenyAccessUnlessCheckSignature::verifyChecksumAndSignature($fileData->getBucket()->getKey(), $sig, $request);

        // check if file is expired or got deleted
        $linkExpiryTime = $bucket->getLinkExpireTime();
        $now = new \DateTime('now');
        $now->sub(new \DateInterval($linkExpiryTime));
        $expiryTime = strtotime($now->format('c'));

        // check if request is expired
        if ((int) $creationTime < $expiryTime || $expiryTime === false) {
            throw ApiError::withDetails(Response::HTTP_FORBIDDEN, 'Parameter creationTime too old', 'blob:download-file-by-id-creation-time-too-old');
        }

        return $this->blobService->getBinaryResponse($fileData);
    }

    public function fileNotFoundResponse(): Response
    {
        $loader = new FilesystemLoader(dirname(__FILE__).'/../Resources/views/');
        $twig = new Environment($loader);

        $template = $twig->load('fileNotFound.html.twig');
        $content = $template->render();

        $response = new Response();
        $response->setContent($content);

        return $response;
    }
}
