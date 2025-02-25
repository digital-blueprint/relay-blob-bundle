<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Service;

use Dbp\Relay\BlobBundle\Configuration\BucketConfig;
use Dbp\Relay\BlobBundle\Configuration\ConfigurationService;
use Dbp\Relay\BlobBundle\Entity\FileData;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\NonUniqueResultException;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Email;
use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;
use Twig\Loader\FilesystemLoader;

class BlobUpdates
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ConfigurationService $configurationService,
        private readonly DatasystemProviderService $datasystemService,
        private readonly BlobService $blobService)
    {
    }

    /**
     * @throws \Exception
     */
    public function updateBucketSizes(?OutputInterface $out = null, $intBucketId = null)
    {
        $buckets = $this->configurationService->getBuckets();
        $someOut = $out ?? new NullOutput();

        foreach ($buckets as $bucket) {
            if (!is_null($intBucketId) && is_string($intBucketId) && $intBucketId !== $bucket->getInternalBucketId()) {
                continue;
            }
            $this->blobService->recalculateAndUpdateBucketSize($intBucketId, $out);
        }
    }
}
