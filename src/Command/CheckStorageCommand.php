<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Command;

use Dbp\Relay\BlobBundle\Service\BlobChecks;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class CheckStorageCommand extends Command
{
    /**
     * @var BlobChecks
     */
    private $blobChecks;

    public function __construct(BlobChecks $blobChecks)
    {
        parent::__construct();

        $this->blobChecks = $blobChecks;
    }

    /**
     * @return void
     */
    protected function configure()
    {
        $this->setName('dbp:relay:blob:check-storage');
        $this->setAliases(['dbp:relay-blob:check-storage']);
        $this
            ->setDescription('Checks the consistency of the two tables in database and the file storage backend');
    }

    /**
     * @throws \Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('Checking the count of items and bucket sizes of the blob_files table, blob_bucket_size table and the data on the file storage backend against each other...');
        $this->blobChecks->checkFileSize($output, false);

        return 0;
    }
}
