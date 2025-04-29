<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Command;

use Dbp\Relay\BlobBundle\Service\BlobChecks;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class CheckBucketSizesCommand extends Command
{
    public function __construct(
        private readonly BlobChecks $blobChecks)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('dbp:relay:blob:check-bucket-sizes');
        $this
            ->setDescription('Calculates the difference between actual bucket sizes (sum of file sizes) and the stored bucket size.')
            ->addOption('int-bucket-id', mode: InputOption::VALUE_OPTIONAL, description: 'The internal bucket ID of the bucket to be tested')
            ->addOption('file', mode: InputOption::VALUE_REQUIRED, description: 'Name of the output file', default: 'bucket_sizes_report')
            ->addOption('stdout', mode: InputOption::VALUE_NONE, description: 'print output to stdout')
            ->addOption('email', mode: InputOption::VALUE_NONE, description: 'print output to stdout');
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

        $output->writeln('Checking bucket sizes...');
        $this->blobChecks->checkBucketSizes($intBucketId,
            $stdout ? $output : null, $file, $email);

        return 0;
    }
}
