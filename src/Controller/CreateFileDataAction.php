<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Controller;

use Dbp\Relay\BlobBundle\Entity\FileData;
use Dbp\Relay\BlobBundle\Helper\BlobUtils;
use Dbp\Relay\BlobBundle\Helper\DenyAccessUnlessCheckSignature;
use Dbp\Relay\BlobBundle\Service\BlobService;
use Dbp\Relay\CoreBundle\Exception\ApiError;
use Dbp\Relay\CoreBundle\Rest\CustomControllerTrait;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class CreateFileDataAction extends AbstractController
{
    use CustomControllerTrait;

    public function __construct(private readonly BlobService $blobService)
    {
    }

    /**
     * @throws HttpException
     * @throws \JsonException
     * @throws \Exception
     */
    public function __invoke(Request $request): FileData
    {
        if ($this->blobService->getAdditionalAuthFromConfig()) {
            $this->requireAuthentication();
        }

        /* check minimal needed parameters for presence and correctness */
        $errorPrefix = 'blob:create-file-data';
        DenyAccessUnlessCheckSignature::checkSignature(
            $errorPrefix, $this->blobService, $request, $request->query->all(), ['POST']);

        // check content-length header to prevent misleading error messages if the upload is too big for the server to accept
        if ($request->headers->get('Content-Length') && intval($request->headers->get('Content-Length')) !== 0
            && intval($request->headers->get('Content-Length')) > BlobUtils::convertFileSizeStringToBytes(ini_get('post_max_size'))) {
            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'Given file is too large', 'blob:create-file-data-file-too-big');
        }

        return $this->blobService->addFile($this->blobService->createFileDataFromRequest($request));
    }
}
