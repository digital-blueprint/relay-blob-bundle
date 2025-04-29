<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Command;

use Dbp\Relay\BlobBundle\Service\BlobChecks;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

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
            ->addOption('file', mode: InputOption::VALUE_REQUIRED, description: 'Name of the output file', default: 'orphan_files_report')
            ->addOption('stdout', mode: InputOption::VALUE_OPTIONAL, description: 'print output to stdout')
            ->addOption('email', mode: InputOption::VALUE_OPTIONAL, description: 'print output to stdout');
    }

    /**
     * @throws \Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $intBucketId = $input->getOption('int-bucket-id');
        $email = $input->getOption('email');
        $file = $input->getOption('file');
        $stdout = $input->getOption('stdout');

        if ($file !== null) {
            $file = $file.'_'.BlobChecks::getDateTimeString(isForFilename: true).'.txt';
        }

        $output->writeln('Searching for orphan files in storage...');
        $this->blobChecks->findOrphanFilesInStorage($intBucketId,
            $stdout ? $output : null, $file, $email !== null);

        return 0;
    }
}
