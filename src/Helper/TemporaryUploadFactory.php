<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Helper;

use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;

/**
 * @internal
 */
class TemporaryUploadFactory extends HttpFoundationFactory
{
    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function getTemporaryPath(): string
    {
        $path = parent::getTemporaryPath();
        // Track allocations before copying, so failed conversions can also be cleaned up.
        $this->temporaryFiles[] = $path;

        return $path;
    }

    public function cleanup(): void
    {
        foreach ($this->temporaryFiles as $path) {
            // Only remove original temporary paths, never the destination of a moved upload.
            @unlink($path);
        }
        $this->temporaryFiles = [];
    }
}
