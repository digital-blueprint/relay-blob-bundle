<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Command;

use Dbp\Relay\BlobBundle\Service\BlobUpdates;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

class UpdateBlobSizesCommand extends Command
{
    /**
     * @var BlobUpdates
     */
    private $blobUpdates;

    public function __construct(BlobUpdates $blobUpdates)
    {
        parent::__construct();

        $this->blobUpdates = $blobUpdates;
    }

    /**
     * @return void
     */
    protected function configure()
    {
        $this->setName('dbp:relay:blob:update-blobSizes');
        $this->setAliases(['dbp:relay-blob:update-blobSizes']);
        $this
            ->setDescription('Updates blob_bucket_sizes table by SUM query over all items of a given bucketID in the blob_files table')
            ->addArgument('int-bucket-id', InputArgument::REQUIRED, 'The internal bucket ID of the bucket which of which the blob_bucket_sizes table should be updated');
    }

    /**
     * @throws \Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $id = $input->getArgument('int-bucket-id');
        /**
         * @var QuestionHelper $helper
         */
        $helper = $this->getHelper('question');
        $question = new ConfirmationQuestion('You are about to overwrite the field current_bucket_size in the blob_bucket_sizes table where identifier='.$id.'. Are you sure you want to continue? [y/n]: ', false);
        $res = $helper->ask($input, $output, $question);
        if (!$res) {
            return Command::FAILURE;
        }
        $output->writeln('Calculating total size and updating table ...');
        $this->blobUpdates->updateBucketSizes($output, $id);

        return Command::SUCCESS;
    }
}
