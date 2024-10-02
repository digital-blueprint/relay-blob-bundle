<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Controller;

use Dbp\Relay\BlobBundle\Helper\DenyAccessUnlessCheckSignature;
use Dbp\Relay\BlobBundle\Service\BlobService;
use Dbp\Relay\CoreBundle\Rest\CustomControllerTrait;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class DownloadAction extends AbstractController
{
    use CustomControllerTrait;

    public function __construct(private readonly BlobService $blobService)
    {
    }

    /**
     * @throws \JsonException
     * @throws \Exception
     */
    public function __invoke(Request $request, string $identifier): Response
    {
        if ($this->blobService->getAdditionalAuthFromConfig()) {
            $this->requireAuthentication();
        }

        $errorPrefix = 'blob:download-file-by-id';
        DenyAccessUnlessCheckSignature::checkSignature(
            $errorPrefix, $this->blobService, $request, $request->query->all(), ['GET']);

        $disableOutputValidation = $request->get('disableOutputValidation', '') === '1';

        return $this->blobService->getBinaryResponse($identifier, [
            BlobService::DISABLE_OUTPUT_VALIDATION_OPTION => $disableOutputValidation,
            BlobService::UPDATE_LAST_ACCESS_TIMESTAMP_OPTION => true,
        ]);
    }
}
