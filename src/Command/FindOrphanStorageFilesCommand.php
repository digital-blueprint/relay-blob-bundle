<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Command;

use Dbp\Relay\BlobBundle\Service\BlobChecks;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

class FindOrphanStorageFilesCommand extends Command
{
    public function __construct(
        private readonly BlobChecks $blobChecks)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('dbp:relay:blob:find-orphan-storage-files');
        $this
            ->setDescription('Finds files in file storage which are not present in the blob files table.')
            ->addOption('int-bucket-id', mode: InputOption::VALUE_OPTIONAL, description: 'The internal bucket ID of the bucket to be tested')
            ->addOption('file', mode: InputOption::VALUE_REQUIRED, description: 'Name of the output file')
            ->addOption('stdout', mode: InputOption::VALUE_NONE, description: 'Print output to stdout (default when no output file is specified)')
            ->addOption('hash', mode: InputOption::VALUE_NONE, description: 'Include SHA-256 hashes of orphan files (reads each orphan file in full)')
            ->addOption('delete', mode: InputOption::VALUE_NONE, description: 'Delete the reported orphan files after confirmation (requires --int-bucket-id)');
    }

    /**
     * @throws \Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $intBucketId = $input->getOption('int-bucket-id');
        $file = $input->getOption('file');
        $delete = $input->getOption('delete');
        $stdout = $input->getOption('stdout') || $file === null || $delete;

        if ($delete && ($intBucketId === null || $intBucketId === '')) {
            $output->writeln('<error>Deletion requires --int-bucket-id with an explicit internal bucket ID.</error>');

            return Command::INVALID;
        }
        if ($delete && !$input->isInteractive()) {
            $output->writeln('<error>Deletion requires interactive confirmation.</error>');

            return Command::INVALID;
        }
        if ($delete) {
            $output->writeln('<comment>Pause uploads and other file changes in this bucket before continuing to avoid deleting files from concurrent uploads.</comment>');
        }

        if ($file !== null) {
            $file = $file.'_'.BlobChecks::getDateTimeString(isForFilename: true).'.txt';
        }

        $output->writeln('Searching for orphan files in storage...');
        try {
            $orphanFileIds = $this->blobChecks->findOrphanFilesInStorage($intBucketId,
                $stdout ? $output : null, $file, $input->getOption('hash'), collectOrphanFiles: $delete);
        } catch (\Exception $exception) {
            $output->writeln('<error>Scan failed: '.$exception->getMessage().'. No files were deleted.</error>');

            return Command::FAILURE;
        }

        if (!$delete) {
            return Command::SUCCESS;
        }
        if ($orphanFileIds === []) {
            $output->writeln('No orphan files found. Nothing to delete.');

            return Command::SUCCESS;
        }

        /** @var QuestionHelper $helper */
        $helper = $this->getHelper('question');
        $question = new ConfirmationQuestion(sprintf(
            'Delete these %d orphan files from bucket "%s"? This cannot be undone. [y/N] ',
            count($orphanFileIds), $intBucketId), false);
        if (!$helper->ask($input, $output, $question)) {
            $output->writeln('Deletion cancelled. No files were deleted.');

            return Command::SUCCESS;
        }

        try {
            return $this->blobChecks->deleteOrphanFilesInStorage($intBucketId, $orphanFileIds, $output, $file) ?
                Command::SUCCESS : Command::FAILURE;
        } catch (\Exception $exception) {
            $output->writeln('<error>Deletion failed: '.$exception->getMessage().'</error>');

            return Command::FAILURE;
        }
    }
}
