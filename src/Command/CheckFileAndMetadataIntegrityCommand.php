<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Command;

use Dbp\Relay\BlobBundle\Service\BlobChecks;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
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
            ->addOption('int-bucket-id', mode: InputOption::VALUE_OPTIONAL, description: 'The internal bucket ID of the bucket to be tested')
            ->addOption('file', mode: InputOption::VALUE_REQUIRED, description: 'Name of the output file', default: 'file_integrity_report')
            ->addOption('stdout', mode: InputOption::VALUE_NONE, description: 'print output to stdout')
            ->addOption('email', mode: InputOption::VALUE_NONE, description: 'print output to stdout')
            ->addOption('debug', mode: InputOption::VALUE_NONE, description: 'print debug output to stdout')
            ->addOption('limit', mode: InputOption::VALUE_OPTIONAL, description: 'only check the given number of files');
    }

    /**
     * @throws \Exception
     * @throws TransportExceptionInterface
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $intBucketId = $input->getOption('int-bucket-id');
        $email = $input->getOption('email');
        $file = $input->getOption('file');
        $stdout = $input->getOption('stdout');
        $debug = $input->getOption('debug');
        $limit = $input->getOption('limit');

        if ($file !== null) {
            $file = $file.'_'.BlobChecks::getDateTimeString(isForFilename: true).'.txt';
        }

        $output->writeln('Checking the integrity of all files and metadata...');

        $options = [];
        if ($debug) {
            $options['debug'] = true;
        }
        if ($limit) {
            $options['limit'] = intval($limit);
        }
        $this->blobChecks->checkFileAndMetadataIntegrity($intBucketId,
            $stdout ? $output : null, $file, $email, $options);

        return 0;
    }
}
