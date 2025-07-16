<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Command;

use Dbp\Relay\BlobBundle\Service\BlobService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class GenerateFileCommand extends Command
{
    public function __construct(
        private readonly BlobService $blobService)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('dbp:relay:blob:generate-file');
        $this
            ->setDescription('Generates a file in blob.')
            ->addArgument('filesize', InputArgument::REQUIRED, 'Size in bytes of the file to be generated.')
            ->addArgument('bucketIdentifier', InputArgument::REQUIRED, 'bucketIdentifier of Bucket in which the file should be saved.')
            ->addArgument('prefix', InputArgument::REQUIRED, 'Prefix saved in the db')
            ->addArgument('filename', InputArgument::REQUIRED, 'File name of the generated file')
            ->addArgument('type', mode: InputArgument::OPTIONAL, description: 'Type of the file')
            ->addArgument('retentionDuration', mode: InputArgument::OPTIONAL, description: 'Retention duration saved in the db')
            ->addArgument('metadata', mode: InputArgument::OPTIONAL, description: 'Type of the file');
    }

    /**
     * @throws \Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $filesize = $input->getArgument('filesize');
        $bucketIdentifier = $input->getArgument('bucketIdentifier');
        $prefix = $input->getArgument('prefix');
        $filename = $input->getArgument('filename');
        $type = $input->getArgument('type') ?? '';
        $retentionDuration = $input->getArgument('retentionDuration') ?? '';
        $metadata = $input->getArgument('metadata') ?? '';

        $output->writeln('Generating blob file in bucket "'.$bucketIdentifier.'" ...');

        $fileData = $this->blobService->generateAndSaveDummyFileDataAndFile($filesize, $bucketIdentifier, $prefix, $filename, $retentionDuration, $metadata, $type);

        $output->writeln('Successfully generated a blob file with identifier "'.$fileData->getIdentifier().'" !');

        return 0;
    }
}
