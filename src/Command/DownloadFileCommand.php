<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Command;

use Dbp\Relay\BlobBundle\Service\BlobService;
use Dbp\Relay\BlobLibrary\Api\BlobApi;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

class DownloadFileCommand extends Command
{
    public function __construct(
        private readonly BlobService $blobService)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('dbp:relay:blob:files:download');
        $this
            ->setDescription('Downloads a file from blob storage to the local filesystem.')
            ->addArgument('identifier', InputArgument::REQUIRED, 'The identifier (UUID) of the file to download.')
            ->addOption('output-dir', 'd', InputOption::VALUE_REQUIRED, 'Directory to save the file into (uses the stored filename).')
            ->addOption('output-file', 'o', InputOption::VALUE_REQUIRED, 'Full output path including filename.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $identifier = $input->getArgument('identifier');
        $outputDir = $input->getOption('output-dir');
        $outputFile = $input->getOption('output-file');

        if ($outputDir !== null && $outputFile !== null) {
            $output->writeln('<error>Options --output-dir and --output-file are mutually exclusive.</error>');

            return Command::FAILURE;
        }

        try {
            $fileData = $this->blobService->getFileData($identifier, [
                BlobService::UPDATE_LAST_ACCESS_TIMESTAMP_OPTION => false,
                BlobApi::DISABLE_OUTPUT_VALIDATION_OPTION => true,
            ]);
        } catch (\Exception $e) {
            $output->writeln('<error>Failed to retrieve file metadata: '.$e->getMessage().'</error>');

            return Command::FAILURE;
        }

        if ($outputFile !== null) {
            $targetPath = $outputFile;
        } elseif ($outputDir !== null) {
            $targetPath = rtrim($outputDir, '/\\').\DIRECTORY_SEPARATOR.$fileData->getFileName();
        } else {
            $targetPath = getcwd().\DIRECTORY_SEPARATOR.$fileData->getFileName();
        }

        if (file_exists($targetPath)) {
            /** @var QuestionHelper $helper */
            $helper = $this->getHelper('question');
            $question = new ConfirmationQuestion('File \''.$targetPath.'\' already exists. Overwrite? [y/n]: ', false);
            if (!$helper->ask($input, $output, $question)) {
                $output->writeln('Download cancelled.');

                return Command::FAILURE;
            }
        }

        try {
            $stream = $this->blobService->getFileStream($fileData);
            $contents = $stream->getContents();
        } catch (\Exception $e) {
            $output->writeln('<error>Failed to download file: '.$e->getMessage().'</error>');

            return Command::FAILURE;
        }

        $expectedHash = $fileData->getFileHash();
        if ($expectedHash !== null) {
            $actualHash = hash('sha256', $contents);
            if ($actualHash !== $expectedHash) {
                $output->writeln('<error>File hash mismatch — file has not been saved.</error>');
                $output->writeln('<error>Expected: '.$expectedHash.'</error>');
                $output->writeln('<error>Got:      '.$actualHash.'</error>');

                return Command::FAILURE;
            }
        }

        if (file_put_contents($targetPath, $contents) === false) {
            $output->writeln('<error>Could not write to: '.$targetPath.'</error>');

            return Command::FAILURE;
        }

        if (($dateModified = $fileData->getDateModified()) !== null) {
            touch($targetPath, $dateModified->getTimestamp());
        }

        $output->writeln('File downloaded to \''.$targetPath.'\'.');

        return Command::SUCCESS;
    }
}
