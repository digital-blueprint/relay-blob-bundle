<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Command;

use Dbp\Relay\BlobBundle\Entity\BucketLock;
use Dbp\Relay\BlobBundle\Helper\BlobUtils;
use Dbp\Relay\BlobBundle\Service\BlobService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ListBucketsCommand extends Command
{
    public function __construct(
        private readonly BlobService $blobService)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('dbp:relay:blob:buckets:list');
        $this
            ->setDescription('Lists all configured blob buckets with their quota and current size.')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: "table" or "json".', 'table');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $format = $input->getOption('format');

        if (!in_array($format, ['table', 'json'], true)) {
            $output->writeln('<error>Invalid format "'.$format.'". Use "table" or "json".</error>');

            return Command::FAILURE;
        }

        try {
            $buckets = $this->blobService->getConfigurationService()->getBuckets();

            $rows = [];
            foreach ($buckets as $bucket) {
                $currentSizeBytes = $this->blobService
                    ->getBucketSizeByInternalIdFromDatabase($bucket->getInternalBucketId())
                    ->getCurrentBucketSize();
                $quotaBytes = $bucket->getQuota() * 1024 * 1024;
                $fileCount = $this->blobService->getFileCountByInternalBucketId($bucket->getInternalBucketId());
                $lock = $this->blobService->getBucketLockByInternalBucketId($bucket->getInternalBucketId());
                $locks = $lock === null ? [] : $this->getLockedMethods($lock);

                $rows[] = [
                    'bucketId' => $bucket->getBucketId(),
                    'internalBucketId' => $bucket->getInternalBucketId(),
                    'files' => $fileCount,
                    'currentSize' => $currentSizeBytes,
                    'quota' => $quotaBytes,
                    'service' => $bucket->getService(),
                    'locks' => $locks,
                ];
            }

            if ($format === 'json') {
                $output->writeln(json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            } else {
                $table = new Table($output);
                $table->setHeaders(['bucketId', 'internalBucketId', 'files', 'currentSize', 'quota', 'service', 'locks']);
                foreach ($rows as $row) {
                    $row['currentSize'] = BlobUtils::formatBytes($row['currentSize']);
                    $row['quota'] = BlobUtils::formatBytes($row['quota']);
                    $row['locks'] = $this->formatMethods($row['locks']);
                    $table->addRow(array_values($row));
                }
                $table->render();
                $output->writeln(count($rows).' bucket(s).');
            }
        } catch (\Exception $e) {
            $output->writeln('<error>'.$e->getMessage().'</error>');

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * @return string[]
     */
    private function getLockedMethods(BucketLock $lock): array
    {
        $methods = [];
        if ($lock->getGetLock()) {
            $methods[] = 'GET';
        }
        if ($lock->getPostLock()) {
            $methods[] = 'POST';
        }
        if ($lock->getPatchLock()) {
            $methods[] = 'PATCH';
        }
        if ($lock->getDeleteLock()) {
            $methods[] = 'DELETE';
        }

        return $methods;
    }

    private function formatMethods(array $methods): string
    {
        return count($methods) === 0 ? 'none' : implode(',', $methods);
    }
}
