<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\ApiPlatform;

use Dbp\Relay\BlobBundle\Authorization\AuthorizationService;
use Dbp\Relay\BlobBundle\Service\BlobService;
use Dbp\Relay\CoreBundle\Exception\ApiError;
use Dbp\Relay\CoreBundle\Rest\CustomControllerTrait;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

class CancelRestoreJobAction extends AbstractController
{
    use CustomControllerTrait;

    public function __construct(private readonly BlobService $blobService, private readonly AuthorizationService $authService)
    {
    }

    /**
     * @throws \JsonException
     * @throws \Exception
     */
    public function __invoke(Request $request, string $identifier): Response
    {
        $this->authService->checkCanAccessMetadataBackup();

        if (empty($this->blobService->getMetadataRestoreJobById($identifier))) {
            throw new ApiError(Response::HTTP_NOT_FOUND, 'The metadata restore job with the given id cannot be found!');
        }

        if (!$this->blobService->checkMetadataRestoreJobRunning($identifier)) {
            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'Cannot cancel already finished job!', 'blob:job-not-running');
        }

        $encoders = [new JsonEncoder()];
        $normalizers = [new ObjectNormalizer()];
        $serializer = new Serializer($normalizers, $encoders);

        return new JsonResponse($serializer->serialize($this->blobService->cancelMetadataRestoreJob($identifier), 'json'), json: true);
    }
}
