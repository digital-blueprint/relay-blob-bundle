<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Service;

use Dbp\Relay\BlobBundle\Configuration\BucketConfig;
use Dbp\Relay\BlobBundle\Configuration\ConfigurationService;
use Dbp\Relay\BlobBundle\Entity\FileData;
use Dbp\Relay\BlobBundle\Helper\BlobUtils;
use Dbp\Relay\CoreBundle\Rest\Query\Filter\FilterTreeBuilder;
use Doctrine\ORM\EntityManager;
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
        private readonly EntityManager $entityManager,
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
            $service = $this->datasystemService->getService($bucket->getService());

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

    public function findOrphanFilesInStorage(?string $internalBucketId = null,
        ?OutputInterface $out = null, ?string $outFileName = null, bool $email = false): void
    {
        $buckets = $internalBucketId !== null ?
            [$this->configurationService->getBucketByInternalID($internalBucketId)] :
            $this->configurationService->getBuckets();

        foreach ($buckets as $bucket) {
            if ($internalBucketId !== null && $internalBucketId !== $bucket->getInternalBucketId()) {
                continue;
            }
            self::output("------------------------------------------------------------------\n", $out, $outFileName);
            self::output(sprintf(self::getDateTimeString()." Orphan files in storage of '%s' (%s)\n",
                $bucket->getBucketId(), $bucket->getInternalBucketId()), $out, $outFileName);
            self::output("------------------------------------------------------------------\n", $out, $outFileName);

            $numFilesChecked = 0;
            $numOrphanFiles = 0;
            $startDate = BlobUtils::now();
            $batchSize = 100;
            $itemCounter = 0;

            foreach ($this->datasystemService->getService($bucket->getService())
                         ->listFiles($bucket->getInternalBucketId()) as $fileId) {
                if (null === $this->entityManager->getRepository(FileData::class)->find($fileId)) {
                    ++$numOrphanFiles;
                    self::output(sprintf("%s\n", $fileId), $out, $outFileName);
                }
                ++$numFilesChecked;
                if (++$itemCounter % $batchSize === 0) {
                    $this->entityManager->clear(); // avoid memory leak
                }
            }
            $endDate = BlobUtils::now();

            self::output("------------------------------------------------------------------\n", $out, $outFileName);
            self::output(sprintf(self::getDateTimeString()." DONE\n"), $out, $outFileName);
            self::output("------------------------------------------------------------------\n", $out, $outFileName);
            self::output(sprintf("Start date: %s\n", self::getDateTimeString($startDate)), $out, $outFileName);
            self::output(sprintf("End date: %s\n", self::getDateTimeString($endDate)), $out, $outFileName);
            self::output(sprintf("Duration: %s\n",
                $startDate->diff($endDate)->format('%d days, %h hours, %i minutes, %s seconds')), $out, $outFileName);
            self::output("------------------------------------------------------------------\n", $out, $outFileName);
            self::output(sprintf("Number of files checked: %d\n", $numFilesChecked), $out, $outFileName);
            self::output(sprintf("Number of orphan files: %d\n", $numOrphanFiles), $out, $outFileName);
            self::output("------------------------------------------------------------------\n", $out, $outFileName);
            self::output("\n", $out, $outFileName);
        }
    }

    public function checkBucketSizes(?string $internalBucketId = null, ?OutputInterface $out = null,
        ?string $outFileName = null, bool $email = false): void
    {
        $buckets = $internalBucketId !== null ?
            [$this->configurationService->getBucketByInternalID($internalBucketId)] :
            $this->configurationService->getBuckets();

        foreach ($buckets as $bucket) {
            self::output("------------------------------------------------------------------\n", $out, $outFileName);
            self::output(sprintf(self::getDateTimeString()." Bucket size check of '%s' (%s)\n",
                $bucket->getBucketId(), $bucket->getInternalBucketId()), $out, $outFileName);
            self::output("------------------------------------------------------------------\n", $out, $outFileName);

            try {
                $this->entityManager->getConnection()->executeQuery('LOCK TABLES blob_files WRITE, blob_bucket_sizes WRITE');

                $getDifferenceStatement = $this->entityManager->getConnection()->prepare('SELECT '.
                    '(SELECT COALESCE(SUM(file_size), 0) FROM blob_files WHERE internal_bucket_id = :internalBucketId) - '.
                    '(SELECT current_bucket_size FROM blob_bucket_sizes WHERE identifier = :internalBucketId) AS difference');
                $getDifferenceStatement->bindValue(':internalBucketId', $bucket->getInternalBucketId());
                $difference = $getDifferenceStatement->executeQuery()->fetchOne();

                self::output(sprintf("Difference (Mb): %f (calculated - stored)\n", (float) $difference / 1048576), $out, $outFileName);
            } catch (\Exception $exception) {
                self::output(sprintf("Bucket size comparison failed: %s\n", $exception->getMessage()), $out, $outFileName);
            } finally {
                // Unlock the tables (if necessary, depending on your DBMS)
                $this->entityManager->getConnection()->executeQuery('UNLOCK TABLES');
            }
            self::output("------------------------------------------------------------------\n", $out, $outFileName);
            self::output("\n", $out, $outFileName);
        }
    }

    /**
     * @throws \Exception
     * @throws TransportExceptionInterface
     */
    public function checkFileAndMetadataIntegrity(?string $internalBucketId = null,
        ?OutputInterface $out = null, ?string $outFileName = null, bool $sendEmail = false, array $options = []): void
    {
        $debug = $options['debug'] ?? false;
        $maxNumFiles = $options['limit'] ?? null;
        $doFileIntegrityChecks = $this->configurationService->doFileIntegrityChecks();
        $maxNumItemsPerPage = 100;
        $buckets = $internalBucketId !== null ?
            [$this->configurationService->getBucketByInternalID($internalBucketId)] :
            $this->configurationService->getBuckets();
        $maxNumStoredIdentifiers = 100; // per criteria

        foreach ($buckets as $bucket) {
            self::output("------------------------------------------------------------------\n", $out, $outFileName);
            self::output(sprintf(self::getDateTimeString()." Bucket integrity of '%s' (%s)\n",
                $bucket->getBucketId(), $bucket->getInternalBucketId()), $out, $outFileName);
            if ($maxNumFiles !== null) {
                self::output(sprintf("Maximum number of files to check: %d\n", $maxNumFiles), $out, $outFileName);
            }
            self::output("------------------------------------------------------------------\n", $out, $outFileName);

            $numFilesNotFound = 0;
            $numFilesWithFileIntegrityViolation = 0;
            $numFilesWithFileSizeViolation = 0;
            $numFilesWithMetadataIntegrityViolation = 0;
            $fileDataIdentifiersNotFoundInFileStorage = [];
            $fileDataIdentifiersWithFileIntegrityViolation = [];
            $fileDataIdentifiersWithFileSizeViolation = [];
            $fileDataIdentifiersWithMetadataIntegrityViolation = [];
            $numFilesChecked = 0;
            $lastIdentifier = null;
            $getFileDataSumMicrotime = 0;
            $compareFileHashSumMicrotime = 0;
            $compareFileSizeSumMicrotime = 0;
            $compareMetadataSumMicrotime = 0;
            $startDate = BlobUtils::now();
            $startMicroTime = microtime(true);
            $finished = false;

            try {
                $filter = FilterTreeBuilder::create()
                    ->equals('internalBucketId', $bucket->getInternalBucketId())
                    ->createFilter();

                do {
                    $microtimeStart = microtime(true);
                    $fileDataCollection =
                        $this->blobService->getFileDataCollectionCursorBased($lastIdentifier, $maxNumItemsPerPage, $filter);
                    $numFilesReturned = count($fileDataCollection);
                    if ($numFilesReturned > 0) {
                        $lastIdentifier = $fileDataCollection[$numFilesReturned - 1]->getIdentifier();
                    }
                    $getFileDataSumMicrotime += (float) microtime(true) - $microtimeStart;
                    if ($debug) {
                        self::output(sprintf("Retrieved %d files\n", $numFilesReturned), $out, $outFileName);
                    }

                    foreach ($fileDataCollection as $fileData) {
                        try {
                            $microtimeStart = microtime(true);
                            if ($doFileIntegrityChecks && $fileData->getFileHash() !== null) {
                                if ($debug) {
                                    self::output(sprintf("Checking file hash of %s\n", $fileData->getIdentifier()), $out, $outFileName);
                                }
                                if ($this->blobService->getFileHashFromStorage($fileData) !== $fileData->getFileHash()) {
                                    if (count($fileDataIdentifiersWithFileIntegrityViolation) < $maxNumStoredIdentifiers) {
                                        $fileDataIdentifiersWithFileIntegrityViolation[] = $fileData->getIdentifier();
                                    }
                                    ++$numFilesWithFileIntegrityViolation;
                                    self::output(sprintf("%s FI\n", $fileData->getIdentifier()), $out, $outFileName);
                                }
                                $compareFileHashSumMicrotime += (float) microtime(true) - $microtimeStart;
                            } else {
                                if ($debug) {
                                    self::output(sprintf("Checking file size of %s\n", $fileData->getIdentifier()), $out, $outFileName);
                                }
                                if ($this->blobService->getFileSizeFromStorage($fileData) !== $fileData->getFileSize()) {
                                    if (count($fileDataIdentifiersWithFileSizeViolation) < $maxNumStoredIdentifiers) {
                                        $fileDataIdentifiersWithFileSizeViolation[] = $fileData->getIdentifier();
                                    }
                                    ++$numFilesWithFileSizeViolation;
                                    self::output(sprintf("%s FS\n", $fileData->getIdentifier()), $out, $outFileName);
                                }
                                $compareFileSizeSumMicrotime += (float) microtime(true) - $microtimeStart;
                            }
                        } catch (\Exception $exception) {
                            if ($debug) {
                                self::output(sprintf("Execption thrown (%s). Checking if file was removed meanwhile.\n",
                                    $exception->getMessage()), $out, $outFileName);
                            }
                            $wasFileRemovedDuringCheck = false;
                            if (null === $this->entityManager->getRepository(FileData::class)->find($fileData->getIdentifier())) {
                                if ($debug) {
                                    self::output("File was removed meanwhile.\n", $out, $outFileName);
                                }
                                $wasFileRemovedDuringCheck = true;
                            }
                            if (false === $wasFileRemovedDuringCheck) {
                                if (count($fileDataIdentifiersNotFoundInFileStorage) < $maxNumStoredIdentifiers) {
                                    $fileDataIdentifiersNotFoundInFileStorage[] = $fileData->getIdentifier();
                                }
                                ++$numFilesNotFound;
                                self::output(sprintf("%s NF\n", $fileData->getIdentifier()), $out, $outFileName);
                            }
                        }

                        if ($doFileIntegrityChecks && $fileData->getMetadataHash() !== null) {
                            $microtimeStart = microtime(true);
                            if ($fileData->getMetadata() === null
                                || hash('sha256', $fileData->getMetadata()) !== $fileData->getMetadataHash()) {
                                if (count($fileDataIdentifiersWithMetadataIntegrityViolation) < $maxNumStoredIdentifiers) {
                                    $fileDataIdentifiersWithMetadataIntegrityViolation[] = $fileData->getIdentifier();
                                }
                                ++$numFilesWithMetadataIntegrityViolation;
                                self::output(sprintf("%s MI\n", $fileData->getIdentifier()), $out, $outFileName);
                            }
                            $compareMetadataSumMicrotime += (float) microtime(true) - $microtimeStart;
                        }
                        ++$numFilesChecked;
                        if ($maxNumFiles !== null && $numFilesChecked >= $maxNumFiles) {
                            $finished = true;
                            break;
                        }
                    }
                    $this->blobService->clearEntityManager(); // prevent memory leak
                } while ($numFilesReturned === $maxNumItemsPerPage && $finished === false);
            } catch (\Exception $exception) {
                self::output(sprintf(self::getDateTimeString().
                    " An error occurred: %s\n%s\nAborting integrity check.\n",
                    $exception->getMessage(), $exception->getTraceAsString()), $out, $outFileName);
            }
            $totalMicrotime = (float) microtime(true) - $startMicroTime;
            $endDate = BlobUtils::now();

            self::output("------------------------------------------------------------------\n", $out, $outFileName);
            self::output(sprintf(self::getDateTimeString()." DONE\n"), $out, $outFileName);
            self::output(sprintf("Start date: %s\n", self::getDateTimeString($startDate)));
            self::output(sprintf("End date: %s\n", self::getDateTimeString($endDate)));
            self::output(sprintf("Duration: %s\n", $startDate->diff($endDate)->format('%d days, %h hours, %i minutes, %s seconds')));
            self::output("------------------------------------------------------------------\n", $out, $outFileName);
            self::output(sprintf("Sum of microtime for getting file data: %s\n", $getFileDataSumMicrotime), $out, $outFileName);
            self::output(sprintf("Sum of microtime for compare file hash: %s\n", $compareFileHashSumMicrotime), $out, $outFileName);
            self::output(sprintf("Sum of microtime for compare file size: %s\n", $compareFileSizeSumMicrotime), $out, $outFileName);
            self::output(sprintf("Sum of microtime for compare metadata: %s\n", $compareMetadataSumMicrotime), $out, $outFileName);
            self::output(sprintf("Total microtime: %s\n", $totalMicrotime), $out, $outFileName);
            self::output("------------------------------------------------------------------\n", $out, $outFileName);
            self::output(sprintf("Number of files checked: %d\n", $numFilesChecked), $out, $outFileName);
            self::output(sprintf("Number of files not found %d\n", $numFilesNotFound), $out, $outFileName);
            self::output(sprintf("Number of files with file integrity violation: %d\n", $numFilesWithFileIntegrityViolation), $out, $outFileName);
            self::output(sprintf("Number of files with metadata integrity violation: %d\n", $numFilesWithMetadataIntegrityViolation), $out, $outFileName);
            self::output("------------------------------------------------------------------\n", $out, $outFileName);
            self::output("\n", $out, $outFileName);

            if ($sendEmail) {
                try {
                    $this->sendIntegrityCheckResultMail($bucket,
                        $numFilesChecked,
                        $numFilesNotFound,
                        $numFilesWithFileIntegrityViolation,
                        $numFilesWithFileSizeViolation,
                        $numFilesWithMetadataIntegrityViolation,
                        $fileDataIdentifiersNotFoundInFileStorage,
                        $fileDataIdentifiersWithFileIntegrityViolation,
                        $fileDataIdentifiersWithFileSizeViolation,
                        $fileDataIdentifiersWithMetadataIntegrityViolation,
                        $startDate, $endDate);
                } catch (\Exception $exception) {
                    $this->logger->error('Failed to send integrity check mail:'.$exception->getMessage());
                    $this->logger->error('Number of files checked: '.$numFilesChecked);
                    $this->logger->error('Number of files not found in storage backend: '.count($fileDataIdentifiersNotFoundInFileStorage));
                    $this->logger->error('Number of files with file integrity violation: '.count($fileDataIdentifiersWithFileIntegrityViolation));
                    $this->logger->error('Number of files with file size violation: '.count($fileDataIdentifiersWithFileSizeViolation));
                    $this->logger->error('Number of files with metadata integrity violation: '.count($fileDataIdentifiersWithMetadataIntegrityViolation));
                }
            }

            if ($finished) {
                break;
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
     * @throws TransportExceptionInterface
     */
    private function sendIntegrityCheckResultMail(BucketConfig $bucket, int $numFilesChecked,
        int $numFilesNotFound,
        int $numFilesWithFileIntegrityViolation,
        int $numFilesWithFileSizeViolation,
        int $numFilesWithMetadataIntegrityViolation,
        array $fileDataIdentifiersNotFoundInFileStorage, array $fileDataIdentifiersWithFileIntegrityViolation,
        array $fileDataIdentifiersWithFileSizeViolation, array $fileDataIdentifiersWithMetadataIntegrityViolation,
        \DateTimeImmutable $startDate, \DateTimeImmutable $endDate): void
    {
        if (!empty($fileDataIdentifiersNotFoundInFileStorage)
            || !empty($fileDataIdentifiersWithFileIntegrityViolation)
            || !empty($fileDataIdentifiersWithFileSizeViolation)
            || !empty($fileDataIdentifiersWithMetadataIntegrityViolation)) {
            $context = [
                'internalBucketId' => $bucket->getInternalBucketId(),
                'bucketId' => $bucket->getBucketId(),
                'numFilesNotFound' => $numFilesNotFound,
                'numFilesWithFileIntegrityViolation' => $numFilesWithFileIntegrityViolation,
                'numFilesWithFileSizeViolation' => $numFilesWithFileSizeViolation,
                'numFilesWithMetadataIntegrityViolation' => $numFilesWithMetadataIntegrityViolation,
                'identifiersNotFound' => $fileDataIdentifiersNotFoundInFileStorage,
                'identifiersWithFileIntegrityViolation' => $fileDataIdentifiersWithFileIntegrityViolation,
                'identifiersWithFileSizeViolation' => $fileDataIdentifiersWithFileSizeViolation,
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

    public static function getDateTimeString(?\DateTimeImmutable $dateTime = null, bool $isForFilename = false): string
    {
        return ($dateTime ?? new \DateTimeImmutable())->format($isForFilename ? 'Y-m-d_H-i-s' : 'Y-m-d H:i:s');
    }

    private static function output(string $message, ?OutputInterface $out = null, ?string $outFileName = null): void
    {
        $out?->write($message);
        if ($outFileName !== null) {
            file_put_contents($outFileName, $message, FILE_APPEND);
        }
    }
}
