<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\ApiPlatform;

use Dbp\Relay\BlobBundle\Configuration\ConfigurationService;
use Dbp\Relay\BlobBundle\Entity\FileData;
use Dbp\Relay\BlobBundle\Helper\SignatureUtils;
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

    public function __construct(private readonly BlobService $blobService, private readonly ConfigurationService $config)
    {
    }

    /**
     * @throws HttpException
     * @throws \JsonException
     * @throws \Exception
     */
    public function __invoke(Request $request): FileData
    {
        if ($this->config->checkAdditionalAuth()) {
            $this->requireAuthentication();
        }

        $errorPrefix = 'blob:create-file-data';
        SignatureUtils::checkSignature($errorPrefix, $this->config, $request, $request->query->all(), ['POST']);

        if ($request->files->get('file') === null) {
            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST,
                'No file with parameter key "file" was received!',
                'blob:create-file-data-missing-file');
        }

        $fileData = $this->blobService->setUpFileDataFromRequest(new FileData(), $request, $errorPrefix);

        return $this->blobService->addFile($fileData, [
            BlobService::BASE_URL_OPTION => $request->getSchemeAndHttpHost(),
        ]);
    }
}
