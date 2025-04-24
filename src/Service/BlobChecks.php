<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Service;

use Dbp\Relay\BlobBundle\Configuration\BucketConfig;
use Dbp\Relay\BlobBundle\Configuration\ConfigurationService;
use Dbp\Relay\BlobBundle\Entity\FileData;
use Dbp\Relay\BlobBundle\Helper\BlobUtils;
use Dbp\Relay\CoreBundle\Rest\Query\Filter\FilterTreeBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\NonUniqueResultException;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
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

class BlobChecks implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ConfigurationService $configurationService,
        private readonly DatasystemProviderService $datasystemService,
        private readonly BlobService $blobService)
    {
    }

    /**
     * Sends reporting and bucket quota warning email if needed.
     *
     * @throws \Exception
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
    public function checkStorage(?OutputInterface $out = null, $sendEmail = true, $internalBucketId = null): void
    {
        $buckets = $this->configurationService->getBuckets();

        // sum of file sizes in the blob_files table
        $sumBucketSizes = [];

        // sum thats saved for each bucket in the blob_bucket_sizes table
        $dbBucketSizes = [];

        // number of files that are in each bucket on the file system
        $countBucketSizes = [];

        foreach ($buckets as $bucket) {
            if ($internalBucketId !== null && $internalBucketId !== $bucket->getInternalBucketId()) {
                continue;
            }
            if (!$sendEmail && $out !== null) {
                $out->writeln('Retrieving database information for bucket with bucket id: '.$bucket->getBucketId().' and internal bucket id: '.$bucket->getInternalBucketId());
                $out->writeln('Calculating sum of fileSizes in the blob_files table ...');
            }
            $query = $this->entityManager
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

                $query = $this->entityManager
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
            if ($internalBucketId !== null && $internalBucketId !== $bucket->getInternalBucketId()) {
                continue;
            }
            $config = $bucket->getBucketSizeConfig();
            if (!$sendEmail && $out !== null) {
                $out->writeln('Retrieving filesystem information for bucket with bucket id: '.$bucket->getBucketId().' and internal bucket id: '.$bucket->getInternalBucketId());
                $out->writeln('Calculating sum of fileSizes in the bucket directory ...');
            }
            $service = $this->datasystemService->getServiceByBucket($bucket);

            $filebackendSize = 0;
            $filebackendNumOfFiles = 0;
            foreach ($service->listFiles($bucket->getInternalBucketId()) as $fileId) {
                $filebackendSize += $service->getFileSize($bucket->getInternalBucketId(), $fileId);
                ++$filebackendNumOfFiles;
            }

            if (!$sendEmail && $out !== null) {
                $out->writeln('Counting number of files in the bucket directory ...');
            }

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
     * @throws \Exception
     * @throws TransportExceptionInterface
     */
    public function checkFileAndMetadataIntegrity(?OutputInterface $out = null, bool $sendEmail = true, bool $printIds = false, ?string $intBucketId = null): void
    {
        $maxNumItemsPerPage = 100;
        $buckets = $intBucketId !== null ?
            [$this->configurationService->getBucketByInternalID($intBucketId)] :
            $this->configurationService->getBuckets();
        foreach ($buckets as $bucket) {
            $startDate = BlobUtils::now();
            if ($out !== null) {
                $out->writeln('----------------------------------------------------------------------------------------------------------');
                $out->writeln(self::getDateTimeString().': Checking file and metadata integrity for bucket \''.$bucket->getBucketId().'\' ('.$bucket->getInternalBucketId().')');
            }
            $fileDataIdentifiersNotFoundInFileStorage = [];
            $fileDataIdentifiersWithFileIntegrityViolation = [];
            $fileDataIdentifiersWithMetadataIntegrityViolation = [];
            $filter = FilterTreeBuilder::create()
                ->equals('internalBucketId', $bucket->getInternalBucketId())
                ->createFilter();
            $numFilesChecked = 0;
            $lastIdentifier = null;
            do {
                $fileDataCollection =
                    $this->blobService->getFileDataCollectionCursorBased($lastIdentifier, $maxNumItemsPerPage, $filter);
                $numFilesChecked += count($fileDataCollection);
                foreach ($fileDataCollection as $fileData) {
                    try {
                        $fileHashFromStorage = $this->blobService->getFileHashFromStorage($fileData);
                    } catch (\Exception) {
                        $fileDataIdentifiersNotFoundInFileStorage[] = $fileData->getIdentifier();
                        continue;
                    }

                    if ($fileData->getFileHash() !== null && $fileHashFromStorage !== $fileData->getFileHash()) {
                        $fileDataIdentifiersWithFileIntegrityViolation[] = $fileData->getIdentifier();
                    }
                    if ($fileData->getMetadataHash() !== null
                        && ($fileData->getMetadata() === null || hash('sha256', $fileData->getMetadata()) !== $fileData->getMetadataHash())) {
                        $fileDataIdentifiersWithMetadataIntegrityViolation[] = $fileData->getIdentifier();
                    }
                }
            } while (count($fileDataCollection) === $maxNumItemsPerPage);
            $endDate = BlobUtils::now();
            if ($sendEmail) {
                try {
                    $this->sendIntegrityCheckResultMail($bucket, $numFilesChecked, $fileDataIdentifiersNotFoundInFileStorage,
                        $fileDataIdentifiersWithFileIntegrityViolation, $fileDataIdentifiersWithMetadataIntegrityViolation,
                        $startDate, $endDate);
                } catch (\Exception $exception) {
                    $this->logger->error('Failed to send integrity check mail:'.$exception->getMessage());
                    $this->logger->error('Number of files checked: '.$numFilesChecked);
                    $this->logger->error('Number of files not found in storage backend: '.count($fileDataIdentifiersNotFoundInFileStorage));
                    $this->logger->error('Number of files with file integrity violation: '.count($fileDataIdentifiersWithFileIntegrityViolation));
                    $this->logger->error('Number of files with metadata integrity violation: '.count($fileDataIdentifiersWithMetadataIntegrityViolation));
                }
            }
            if ($out !== null) {
                $this->printIntegrityCheckResult($bucket, $numFilesChecked, $fileDataIdentifiersNotFoundInFileStorage,
                    $fileDataIdentifiersWithFileIntegrityViolation, $fileDataIdentifiersWithMetadataIntegrityViolation,
                    $out, $printIds, $startDate, $endDate);
            }
        }
    }

    /**
     * Checks whether some files will expire soon, and sends a email to the bucket owner
     * or owner of the file (if configured as notifyEmail).
     *
     * @param string[] $fileDataIdentifiersNotFoundInFileStorage
     * @param string[] $fileDataIdentifiersWithFileIntegrityViolation
     * @param string[] $fileDataIdentifiersWithMetadataIntegrityViolation
     *
     * @throws \Exception
     */
    private function printIntegrityCheckResult(BucketConfig $bucket, int $numFilesChecked, array $fileDataIdentifiersNotFoundInFileStorage,
        array $fileDataIdentifiersWithFileIntegrityViolation, array $fileDataIdentifiersWithMetadataIntegrityViolation,
        OutputInterface $out, bool $printIds, \DateTimeImmutable $startDate, \DateTimeImmutable $endDate): void
    {
        if (!empty($fileDataIdentifiersNotFoundInFileStorage)
            || !empty($fileDataIdentifiersWithFileIntegrityViolation)
            || !empty($fileDataIdentifiersWithMetadataIntegrityViolation)) {
            $out->writeln(self::getDateTimeString().': WARNING: Found integrity violations in bucket \''.$bucket->getBucketId().'\' ('.$bucket->getInternalBucketId().')');
            if ($printIds === true) {
                if (!empty($fileDataIdentifiersNotFoundInFileStorage)) {
                    $out->writeln('The following blob files were not found in the storage backend: ');
                    foreach ($fileDataIdentifiersNotFoundInFileStorage as $identifier) {
                        $out->writeln($identifier);
                    }
                }
                if (!empty($fileDataIdentifiersWithFileIntegrityViolation)) {
                    $out->writeln('The following blob files have a file integrity violation: ');
                    foreach ($fileDataIdentifiersWithFileIntegrityViolation as $identifier) {
                        $out->writeln($identifier);
                    }
                }
                if (!empty($fileDataIdentifiersWithMetadataIntegrityViolation)) {
                    $out->writeln('The following blob files have a metadata integrity violation: ');
                    foreach ($fileDataIdentifiersWithMetadataIntegrityViolation as $identifier) {
                        $out->writeln($identifier);
                    }
                }
            }
            $out->writeln('Number of files not found in storage backend: '.count($fileDataIdentifiersNotFoundInFileStorage));
            $out->writeln('Number of files with file integrity violation: '.count($fileDataIdentifiersWithFileIntegrityViolation));
            $out->writeln('Number of files with metadata integrity violation: '.count($fileDataIdentifiersWithMetadataIntegrityViolation));
        } else {
            $out->writeln(self::getDateTimeString().': No file nor metadata integrity violation detected for bucket \''.$bucket->getBucketId().'\' ('.$bucket->getInternalBucketId().')');
        }
        $out->writeln('Number of files checked: '.$numFilesChecked);
        $out->writeln('Start time: '.self::getDateTimeString($startDate));
        $out->writeln('End time: '.self::getDateTimeString($endDate));
        $out->writeln('Duration: '.$startDate->diff($endDate)->format('%d days, %h hours, %i minutes, %s seconds'));
    }

    /**
     * Checks whether some files will expire soon, and sends a email to the bucket owner
     * or owner of the file (if configured as notifyEmail).
     *
     * @param string[] $fileDataIdentifiersNotFoundInFileStorage
     * @param string[] $fileDataIdentifiersWithFileIntegrityViolation
     * @param string[] $fileDataIdentifiersWithMetadataIntegrityViolation
     *
     * @throws \Exception
     * @throws TransportExceptionInterface
     */
    private function sendIntegrityCheckResultMail(BucketConfig $bucket, int $numFilesChecked, array $fileDataIdentifiersNotFoundInFileStorage,
        array $fileDataIdentifiersWithFileIntegrityViolation, array $fileDataIdentifiersWithMetadataIntegrityViolation,
        \DateTimeImmutable $startDate, \DateTimeImmutable $endDate): void
    {
        if (!empty($fileDataIdentifiersNotFoundInFileStorage)
            || !empty($fileDataIdentifiersWithFileIntegrityViolation)
            || !empty($fileDataIdentifiersWithMetadataIntegrityViolation)) {
            $context = [
                'internalBucketId' => $bucket->getInternalBucketId(),
                'bucketId' => $bucket->getBucketId(),
                'identifiersNotFound' => $fileDataIdentifiersNotFoundInFileStorage,
                'identifiersWithFileIntegrityViolation' => $fileDataIdentifiersWithFileIntegrityViolation,
                'identifiersWithMetadataIntegrityViolation' => $fileDataIdentifiersWithMetadataIntegrityViolation,
                'numFilesChecked' => $numFilesChecked,
                'startDateTime' => self::getDateTimeString($startDate),
                'endDateTime' => self::getDateTimeString($endDate),
                'duration' => $startDate->diff($endDate)->format('%d days, %h hours, %i minutes, %s seconds'),
            ];
            $integrityConfig = $bucket->getIntegrityCheckConfig();
            $integrityConfig['subject'] = 'Blob Integrity Check Report ('.$bucket->getBucketId().')';

            $this->sendEmail($integrityConfig, $context);
        }
    }

    public static function getDateTimeString(?\DateTimeImmutable $dateTime = null): string
    {
        return ($dateTime ?? new \DateTimeImmutable())->format('Y-m-d H:i:s');
    }
}
