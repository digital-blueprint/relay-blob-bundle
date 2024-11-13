<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Service;

use Dbp\Relay\BlobBundle\Configuration\BucketConfig;
use Dbp\Relay\BlobBundle\Configuration\ConfigurationService;
use Dbp\Relay\BlobBundle\Entity\FileData;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\NonUniqueResultException;
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

class BlobChecks
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ConfigurationService $configurationService,
        private readonly DatasystemProviderService $datasystemService,
        private readonly BlobService $blobService)
    {
    }

    /**
     * Sends reporting and bucket quota warning email if needed.
     */
    public function checkQuotas(): void
    {
        $buckets = $this->configurationService->getBuckets();
        foreach ($buckets as $bucket) {
            $this->checkBucketQuotaAndSendWarning($bucket);
        }
    }

    /**
     * Checks whether the bucket is filled to a preconfigured percentage, and sends a warning email if so.
     *
     * @throws NonUniqueResultException
     * @throws \Exception
     */
    public function checkBucketQuotaAndSendWarning(BucketConfig $bucket): void
    {
        $bucketSize = $this->blobService->getBucketSizeByInternalIdFromDatabase($bucket->getInternalBucketId());
        // Check quota
        $bucketQuotaByte = $bucketSize->getCurrentBucketSize();
        $bucketWarningQuotaByte = $bucket->getQuota() * 1024 * 1024 * ($bucket->getNotifyWhenQuotaOver() / 100); // Convert mb to Byte and then calculate the warning quota
        if (floatval($bucketQuotaByte) > floatval($bucketWarningQuotaByte)) {
            $this->sendQuotaWarning($bucket, floatval($bucketQuotaByte));
        }
    }

    /**
     * Sends a warning email with information about the buckets used quota.
     */
    public function sendQuotaWarning(BucketConfig $bucket, float $bucketQuotaByte): void
    {
        $notifyQuotaConfig = $bucket->getWarnQuotaOverConfig();

        $id = $bucket->getInternalBucketId();
        $name = $bucket->getBucketId();
        $quota = $bucket->getQuota();

        $context = [
            'internalBucketId' => $id,
            'bucketId' => $name,
            'quota' => $quota,
            'filledTo' => ($bucketQuotaByte / ($quota * 1024 * 1024)) * 100,
        ];

        $this->sendEmail($notifyQuotaConfig, $context);
    }

    /**
     * Wrapper to send an email from a given context.
     *
     * @throws TransportExceptionInterface
     * @throws LoaderError
     * @throws RuntimeError
     * @throws SyntaxError
     */
    private function sendEmail(array $config, array $context): void
    {
        $loader = new FilesystemLoader(__DIR__.'/../Resources/views/');
        $twig = new Environment($loader);

        $template = $twig->load($config['html_template']);
        $html = $template->render($context);

        $transport = Transport::fromDsn($config['dsn']);
        $mailer = new Mailer($transport);

        $email = (new Email())
            ->from($config['from'])
            ->to($config['to'])
            ->subject($config['subject'])
            ->html($html);

        $mailer->send($email);
    }

    /**
     * @throws SyntaxError
     * @throws TransportExceptionInterface
     * @throws RuntimeError
     * @throws LoaderError
     */
    public function checkFileSize(?OutputInterface $out = null, $sendEmail = true)
    {
        $buckets = $this->configurationService->getBuckets();

        // sum of file sizes in the blob_files table
        $sumBucketSizes = [];

        // sum thats saved for each bucket in the blob_bucket_sizes table
        $dbBucketSizes = [];

        // number of files that are in each bucket on the file system
        $countBucketSizes = [];

        foreach ($buckets as $bucket) {
            if (!$sendEmail && $out !== null) {
                $out->writeln('Retrieving database information for bucket with bucket id: '.$bucket->getBucketId().' and internal bucket id: '.$bucket->getInternalBucketId());
                $out->writeln('Calculating sum of fileSizes in the blob_files table ...');
            }
            $query = $this->em
                ->getRepository(FileData::class)
                ->createQueryBuilder('f')
                ->where('f.internalBucketId = :bucketID')
                ->setParameter('bucketID', $bucket->getInternalBucketId())
                ->select('SUM(f.fileSize) as bucketSize');

            $result = $query->getQuery()->getOneOrNullResult();

            if ($result) {
                $bucketSize = $result['bucketSize'];

                // bucketSize will be null if there is no file in the bucket
                if ($bucketSize) {
                    $bucketSize = (int) $bucketSize;
                } else {
                    $bucketSize = 0;
                }

                $sumBucketSizes[$bucket->getInternalBucketId()] = $bucketSize;

                $bucketSizeObject = $this->blobService->getBucketSizeByInternalIdFromDatabase(
                    $bucket->getInternalBucketId());
                $savedBucketSize = $bucketSizeObject->getCurrentBucketSize();

                $dbBucketSizes[$bucket->getInternalBucketId()] = $savedBucketSize;

                if (!$sendEmail && $out !== null) {
                    $out->writeln('Counting number of entries in the blob_files table ...');
                }

                $query = $this->em
                    ->getRepository(FileData::class)
                    ->createQueryBuilder('f')
                    ->where('f.internalBucketId = :bucketID')
                    ->setParameter('bucketID', $bucket->getInternalBucketId())
                    ->select('COUNT(f.identifier) as numOfItems');

                $result = $query->getQuery()->getOneOrNullResult();

                if ($result) {
                    $bucketFilesCount = $result['numOfItems'];

                    $countBucketSizes[$bucket->getInternalBucketId()] = $bucketFilesCount;
                }
            }
            if (!$sendEmail && $out !== null) {
                $out->writeln(' ');
            }
        }

        foreach ($buckets as $bucket) {
            $config = $bucket->getBucketSizeConfig();
            if (!$sendEmail && $out !== null) {
                $out->writeln('Retrieving filesystem information for bucket with bucket id: '.$bucket->getBucketId().' and internal bucket id: '.$bucket->getInternalBucketId());
                $out->writeln('Calculating sum of fileSizes in the bucket directory ...');
            }
            $service = $this->datasystemService->getServiceByBucket($bucket);
            $filebackendSize = $service->getSumOfFilesizesOfBucket($bucket->getInternalBucketId());

            if (!$sendEmail && $out !== null) {
                $out->writeln('Counting number of files in the bucket directory ...');
            }

            $filebackendNumOfFiles = $service->getNumberOfFilesInBucket($bucket->getInternalBucketId());

            $bucketSize = $sumBucketSizes[$bucket->getInternalBucketId()];
            $savedBucketSize = $dbBucketSizes[$bucket->getInternalBucketId()];
            $bucketFilesCount = $countBucketSizes[$bucket->getInternalBucketId()];

            if (($bucketSize !== $savedBucketSize || $bucketSize !== $filebackendSize || $savedBucketSize !== $filebackendSize) || $filebackendNumOfFiles !== $bucketFilesCount) {
                $context = [
                    'internalBucketId' => $bucket->getInternalBucketId(),
                    'bucketId' => $bucket->getBucketId(),
                    'blobFilesSize' => $bucketSize,
                    'blobFilesCount' => $bucketFilesCount,
                    'blobBucketSizes' => $savedBucketSize,
                    'blobBackendSize' => $filebackendSize,
                    'blobBackendCount' => $filebackendNumOfFiles,
                ];

                if ($sendEmail) {
                    $this->sendEmail($config, $context);
                } elseif ($out !== null) {
                    $this->printFileSizeCheck($out, $context);
                }
            } else {
                if (!$sendEmail && $out !== null) {
                    $out->writeln('Everything as expected!');
                    $out->writeln(' ');
                }
            }
        }
    }

    private function printFileSizeCheck(OutputInterface $out, array $context): void
    {
        $out->writeln('Sum of sizes of the blob_files table: '.$context['blobFilesSize']);
        $out->writeln('Number of entries in the blob_files table: '.$context['blobFilesCount']);
        $out->writeln('Stored sum of sizes in the blob_bucket_sizes table: '.$context['blobBucketSizes']);
        $out->writeln('Sum of sizes in the storage backend: '.$context['blobBackendSize']);
        $out->writeln('Number of files in the storage backend: '.$context['blobBackendCount']);
        $out->writeln(' ');
    }

    /**
     * Checks whether some files will expire soon, and sends a email to the bucket owner
     * or owner of the file (if configured as notifyEmail).
     *
     * @return void
     *
     * @throws \Exception
     */
    public function sendIntegrityCheckMail(BucketConfig $bucket, array $invalidDatas)
    {
        $integrityConfig = $bucket->getIntegrityCheckConfig();

        $id = $bucket->getInternalBucketId();
        $name = $bucket->getBucketId();

        if (!empty($invalidDatas)) {
            // create for each email to be notified an array with expiring filedatas
            $files = [];
            foreach ($invalidDatas as $fileData) {
                $file = [];
                /* @var ?FileData $fileData */
                $file['id'] = $fileData->getIdentifier();
                $file['fileName'] = $fileData->getFileName();
                $file['prefix'] = $fileData->getPrefix();
                $file['dateCreated'] = $fileData->getDateCreated()->format('c');
                $file['lastAccess'] = $fileData->getLastAccess()->format('c');
                if ($fileData->getDeleteAt() !== null) {
                    $file['deleteAt'] = $fileData->getDeleteAt()->format('c');
                } else {
                    $file['deleteAt'] = 'null';
                }
                if (empty($notifyEmails[$fileData->getNotifyEmail()])) {
                    $notifyEmails[$fileData->getNotifyEmail()] = [];
                }
                $files[] = $file;
            }

            $context = [
                'internalBucketId' => $id,
                'bucketId' => $name,
                'files' => $files,
            ];

            $config = $integrityConfig;
            $this->sendEmail($config, $context);
        }
    }

    /**
     * @throws \Exception
     */
    public function checkIntegrity(?OutputInterface $out = null, $sendEmail = true, $printIds = false)
    {
        $buckets = $this->configurationService->getBuckets();

        foreach ($buckets as $bucket) {
            $invalidDatas = [];
            foreach ($this->blobService->getFileDataByBucketID($bucket->getInternalBucketId()) as $fileData) {
                try {
                    $content = $this->blobService->getContent($fileData);
                } catch (\Exception) {
                    $invalidDatas[] = $fileData;
                    continue;
                }

                /** @var FileData $fileData */
                if ($fileData->getFileHash() !== null && hash('sha256', $content) !== $fileData->getFileHash()) {
                    $invalidDatas[] = $fileData;
                } elseif ($fileData->getMetadataHash() !== null
                    && ($fileData->getMetadata() === null || hash('sha256', $fileData->getMetadata()) !== $fileData->getMetadataHash())) {
                    $invalidDatas[] = $fileData;
                }
                // dont overfill the email
                if ($sendEmail && count($invalidDatas) > 19) {
                    break;
                }
            }

            if ($sendEmail) {
                $this->sendIntegrityCheckMail($bucket, $invalidDatas);
            } elseif ($out !== null) {
                $this->printIntegrityCheck($bucket, $invalidDatas, $out, $printIds);
            }
        }
    }

    /**
     * Checks whether some files will expire soon, and sends a email to the bucket owner
     * or owner of the file (if configured as notifyEmail).
     *
     * @return void
     *
     * @throws \Exception
     */
    private function printIntegrityCheck(BucketConfig $bucket, array $invalidDatas, OutputInterface $out, bool $printIds = false)
    {
        if (!empty($invalidDatas)) {
            $out->writeln('Found invalid data for bucket with bucket id: '.$bucket->getBucketId().' and internal bucket id: '.$bucket->getInternalBucketId());
            if ($printIds === true) {
                $out->writeln('The following blob file ids contain either invalid filedata or metadata:');
                // print all identifiers that failed the integrity check
                foreach ($invalidDatas as $fileData) {
                    /* @var ?FileData $fileData */
                    $out->writeln($fileData->getIdentifier());
                }
            }
            $out->writeln('In total, '.count($invalidDatas).' files are invalid!');
            $out->writeln(' ');
        } else {
            $out->writeln('No invalid data was found for bucket with bucket id: '.$bucket->getBucketId().' and internal bucket id'.$bucket->getInternalBucketId());
        }
    }
}
