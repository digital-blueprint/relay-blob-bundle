<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Command;

use Dbp\Relay\BlobBundle\Service\BlobChecks;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class CheckDataIntegrityCommand extends Command
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
        $this->setName('dbp:relay-blob:check-integrity');
        $this
            ->setDescription('Checks the file and metadata hashes stored in the table against newly generated hashes of the stored files and metadata');
        $this->addOption('ids', null, null, 'List all the UUIDs of the found inconsistent data');
    }

    /**
     * @throws \Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $ids = $input->getOption('ids');
        $output->writeln('Checking the integrity of all files and metadata...');
        $this->blobChecks->checkIntegrity($output, false, $ids);

        return 0;
    }
}
