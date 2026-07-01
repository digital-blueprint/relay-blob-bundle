<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Command;

use Dbp\Relay\BlobBundle\Helper\BlobUtils;
use Dbp\Relay\BlobBundle\Service\BlobService;
use Dbp\Relay\CoreBundle\Rest\Query\Filter\FilterTreeBuilder;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

class ClearBucketCommand extends Command
{
    public function __construct(
        private readonly BlobService $blobService)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('dbp:relay:blob:buckets:clear');
        $this
            ->setDescription('Deletes all files in a bucket.')
            ->addArgument('bucketIdentifier', InputArgument::REQUIRED, 'The public bucket identifier of the bucket to clear.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $bucketIdentifier = $input->getArgument('bucketIdentifier');

        // 1. Validate that the bucket exists in the configuration.
        $bucketConfig = $this->blobService->getConfigurationService()->getBucketById($bucketIdentifier);
        if ($bucketConfig === null) {
            $output->writeln('<error>Bucket "'.$bucketIdentifier.'" not found in configuration.</error>');

            return Command::FAILURE;
        }

        $internalBucketId = $bucketConfig->getInternalBucketId();

        try {
            $fileCount = $this->blobService->getFileCountByInternalBucketId($internalBucketId);
            $bucketSize = $this->blobService->getBucketSizeByInternalIdFromDatabase($internalBucketId);
            $currentSizeBytes = $bucketSize->getCurrentBucketSize();
            $quotaBytes = $bucketConfig->getQuota() * 1024 * 1024;
        } catch (\Exception $e) {
            $output->writeln('<error>'.$e->getMessage().'</error>');

            return Command::FAILURE;
        }

        // 2. Show bucket information.
        $output->writeln('');
        $table = new Table($output);
        $table->setHeaders(['Property', 'Value']);
        $table->addRows([
            ['Bucket ID', $bucketConfig->getBucketId()],
            ['Internal bucket ID', $internalBucketId],
            ['Storage service', $bucketConfig->getService()],
            ['Quota', BlobUtils::formatBytes($quotaBytes)],
            ['Current size', BlobUtils::formatBytes($currentSizeBytes)],
            ['Files to delete', $fileCount],
        ]);
        $table->render();
        $output->writeln('');

        if ($fileCount === 0) {
            $output->writeln('The bucket is already empty. Nothing to do.');

            return Command::SUCCESS;
        }

        // 3. Ask the user to type the bucket name to confirm.
        $output->writeln('<comment>This will permanently delete all '.$fileCount.' file(s) in bucket "'.$bucketIdentifier.'".</comment>');
        $output->writeln('<comment>This action cannot be undone.</comment>');
        $output->writeln('');

        /** @var QuestionHelper $helper */
        $helper = $this->getHelper('question');
        $question = new Question('Type the bucket name to confirm deletion: ');
        $typedName = $helper->ask($input, $output, $question);

        if ($typedName !== $bucketIdentifier) {
            $output->writeln('');
            $output->writeln('<error>Bucket name does not match. Aborting.</error>');

            return Command::FAILURE;
        }

        $output->writeln('');

        // 4. Delete all files with a progress bar.
        $progressBar = new ProgressBar($output, $fileCount);
        $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% — %message%');
        $progressBar->setMessage('Starting...');
        $progressBar->start();

        $deleted = 0;
        $failedFiles = [];
        $lastIdentifier = null;
        $batchSize = 1000;
        $filter = FilterTreeBuilder::create()
            ->equals('internalBucketId', $internalBucketId)
            ->createFilter();

        try {
            do {
                $batch = $this->blobService->getFileDataCollectionCursorBased($lastIdentifier, $batchSize, $filter);

                foreach ($batch as $fileData) {
                    $identifier = $fileData->getIdentifier();
                    $lastIdentifier = $identifier;

                    $progressBar->setMessage(sprintf(
                        'Deleting "%s" (%s)',
                        $fileData->getFileName() ?? $identifier,
                        BlobUtils::formatBytes($fileData->getFileSize() ?? 0)
                    ));

                    try {
                        if ($fileData->getInternalBucketId() !== $internalBucketId) {
                            throw new \RuntimeException(sprintf(
                                'Refusing to delete file "%s": expected internal bucket ID "%s" but got "%s".',
                                $identifier,
                                $internalBucketId,
                                $fileData->getInternalBucketId()
                            ));
                        }

                        $fileData->setBucketId($bucketConfig->getBucketId());
                        $this->blobService->removeFile($fileData);

                        ++$deleted;
                    } catch (\Exception $e) {
                        $failedFiles[] = [$identifier, $e->getMessage()];
                    }

                    $progressBar->advance();
                }
            } while (count($batch) === $batchSize);
        } catch (\Exception $e) {
            $progressBar->finish();
            $output->writeln('');
            $output->writeln('');
            $output->writeln('<error>Error after deleting '.$deleted.' file(s): '.$e->getMessage().'</error>');

            return Command::FAILURE;
        }

        $progressBar->setMessage('Done.');
        $progressBar->finish();

        $output->writeln('');
        $output->writeln('');

        if ($failedFiles !== []) {
            $output->writeln('<error>Deleted '.$deleted.' file(s), but failed to delete '.count($failedFiles).' file(s) from bucket "'.$bucketIdentifier.'".</error>');
            $output->writeln('');

            $table = new Table($output);
            $table->setHeaders(['File ID', 'Error']);
            $table->setRows($failedFiles);
            $table->render();

            return Command::FAILURE;
        }

        $output->writeln('<info>Successfully deleted '.$deleted.' file(s) from bucket "'.$bucketIdentifier.'".</info>');

        return Command::SUCCESS;
    }
}
