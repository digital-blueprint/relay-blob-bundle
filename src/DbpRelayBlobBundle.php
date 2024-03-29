<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle;

use Dbp\Relay\BlobBundle\Service\DatasystemProviderServiceCompilerPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

class DbpRelayBlobBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        DatasystemProviderServiceCompilerPass::register($container);
    }
}
