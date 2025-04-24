<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Command;

use Dbp\Relay\BlobBundle\Service\BlobChecks;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

class CheckFileAndMetadataIntegrityCommand extends Command
{
    public function __construct(
        private readonly BlobChecks $blobChecks)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('dbp:relay:blob:check-integrity');
        $this->setAliases(['dbp:relay-blob:check-integrity']);
        $this
            ->setDescription('Checks the file and metadata hashes stored in the table against newly generated hashes of the stored files and metadata')
            ->addArgument('int-bucket-id', mode: InputArgument::OPTIONAL, description: 'The internal bucket ID of the bucket to be tested')
            ->addArgument('ids', mode: InputArgument::OPTIONAL, description: 'List all the UUIDs of the found inconsistent data');
    }

    /**
     * @throws \Exception
     * @throws TransportExceptionInterface
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $intBucketId = $input->getArgument('int-bucket-id');
        $printIds = $input->getArgument('ids') ?? true;
        $output->writeln('Checking the integrity of all files and metadata...');
        $this->blobChecks->checkFileAndMetadataIntegrity($output, true, $printIds, $intBucketId);

        return 0;
    }
}
