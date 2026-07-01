<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Command;

use Dbp\Relay\BlobBundle\Service\BlobService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class UnlockBucketCommand extends Command
{
    public function __construct(
        private readonly BlobService $blobService)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('dbp:relay:blob:buckets:unlock');
        $this
            ->setDescription('Removes the lock from a blob bucket.')
            ->addArgument('bucketIdentifier', InputArgument::REQUIRED, 'The public bucket identifier.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $bucketIdentifier = $input->getArgument('bucketIdentifier');
        $bucketConfig = $this->blobService->getConfigurationService()->getBucketById($bucketIdentifier);
        if ($bucketConfig === null) {
            $output->writeln('<error>Bucket "'.$bucketIdentifier.'" not found in configuration.</error>');

            return Command::FAILURE;
        }

        $lock = $this->blobService->getBucketLockByInternalBucketId($bucketConfig->getInternalBucketId());
        if ($lock === null) {
            $output->writeln('<info>Bucket "'.$bucketIdentifier.'" is already unlocked.</info>');

            return Command::SUCCESS;
        }

        try {
            $this->blobService->removeBucketLock($lock->getIdentifier());
        } catch (\Exception $e) {
            $output->writeln('<error>'.$e->getMessage().'</error>');

            return Command::FAILURE;
        }

        $output->writeln('<info>Bucket "'.$bucketIdentifier.'" unlocked.</info>');
        $output->writeln('Removed lock: '.$lock->getIdentifier());

        return Command::SUCCESS;
    }
}
