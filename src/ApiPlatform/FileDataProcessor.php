<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\ApiPlatform;

use Dbp\Relay\BlobBundle\Configuration\ConfigurationService;
use Dbp\Relay\BlobBundle\Entity\FileData;
use Dbp\Relay\BlobBundle\Helper\BlobUtils;
use Dbp\Relay\BlobBundle\Service\BlobService;
use Dbp\Relay\CoreBundle\Rest\AbstractDataProcessor;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * @internal
 */
class FileDataProcessor extends AbstractDataProcessor
{
    public function __construct(
        private readonly BlobService $blobService,
        private readonly RequestStack $requestStack,
        private readonly ConfigurationService $config)
    {
        parent::__construct();
    }

    protected function requiresAuthentication(int $operation): bool
    {
        return $this->config->checkAdditionalAuth();
    }

    /**
     * NOTE: signature check is already done in the FileDataProvider, which is internally called by ApiPlatform to get the
     * FileData entity with the given identifier.
     *
     * @throws \Exception
     */
    protected function updateItem(mixed $identifier, mixed $data, mixed $previousData, array $filters): FileData
    {
        assert($data instanceof FileData);
        assert($previousData instanceof FileData);

        $fileData = $this->blobService->setUpFileDataFromRequest($data,
            BlobUtils::convertPatchRequest($this->requestStack->getCurrentRequest()),
            'blob:patch-file-data');

        return $this->blobService->updateFile($fileData, $previousData);
    }

    /**
     *  NOTE: signature check is already done in the FileDataProvider, which is internally called by ApiPlatform to get the
     *  FileData entity with the given identifier.
     *
     * @throws \Exception
     */
    protected function removeItem(mixed $identifier, mixed $data, array $filters): void
    {
        assert($data instanceof FileData);
        $this->blobService->removeFile($data);
    }
}
