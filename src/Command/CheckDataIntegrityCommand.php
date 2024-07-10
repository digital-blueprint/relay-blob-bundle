<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Command;

use Dbp\Relay\BlobBundle\Service\BlobService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class CheckDataIntegrityCommand extends Command
{
    /**
     * @var BlobService
     */
    private $blobService;

    public function __construct(BlobService $blobService)
    {
        parent::__construct();

        $this->blobService = $blobService;
    }

    /**
     * @return void
     */
    protected function configure()
    {
        $this->setName('dbp:relay-blob:check-integrity');
        $this
            ->setDescription('Checks the file and metadata hashes stored in the table against newly generated hashes of the stored files and metadata');
    }

    /**
     * @throws \Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('Checking the integrity of all files and metadata...');
        $this->blobService->checkIntegrity($output, false);

        return 0;
    }
}
