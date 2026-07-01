<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Command;

use Dbp\Relay\BlobBundle\Entity\BucketLock;
use Dbp\Relay\BlobBundle\Service\BlobService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Uid\Uuid;

class LockBucketCommand extends Command
{
    private const METHODS = ['GET', 'POST', 'PATCH', 'DELETE'];
    private const WRITE_METHODS = ['POST', 'PATCH', 'DELETE'];

    public function __construct(
        private readonly BlobService $blobService)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('dbp:relay:blob:buckets:lock');
        $this
            ->setDescription('Locks a blob bucket for selected HTTP methods.')
            ->addArgument('bucketIdentifier', InputArgument::REQUIRED, 'The public bucket identifier.')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Lock all methods: GET, POST, PATCH, DELETE.')
            ->addOption('read-only', null, InputOption::VALUE_NONE, 'Lock write methods only: POST, PATCH, DELETE.')
            ->addOption('method', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'HTTP method to lock. Can be passed multiple times.')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Replace an existing lock without asking.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $bucketIdentifier = $input->getArgument('bucketIdentifier');
        $bucketConfig = $this->blobService->getConfigurationService()->getBucketById($bucketIdentifier);
        if ($bucketConfig === null) {
            $output->writeln('<error>Bucket "'.$bucketIdentifier.'" not found in configuration.</error>');

            return Command::FAILURE;
        }

        try {
            $methods = $this->getSelectedMethods($input);
        } catch (\InvalidArgumentException $e) {
            $output->writeln('<error>'.$e->getMessage().'</error>');

            return Command::FAILURE;
        }

        $internalBucketId = $bucketConfig->getInternalBucketId();
        $lock = $this->blobService->getBucketLockByInternalBucketId($internalBucketId);
        if ($lock !== null) {
            $currentMethods = $this->getLockedMethods($lock);
            if ($currentMethods === $methods) {
                $output->writeln('<info>Bucket "'.$bucketIdentifier.'" is already locked with the requested methods.</info>');
                $this->writeLockStatus($output, $lock);

                return Command::SUCCESS;
            }

            $output->writeln('Bucket "'.$bucketIdentifier.'" already has a lock.');
            $output->writeln('');
            $output->writeln('Current lock:');
            $this->writeLockStatus($output, $lock);
            $output->writeln('');
            $output->writeln('Requested lock:');
            $output->writeln('Locks: '.$this->formatMethods($methods));
            $output->writeln('');

            if (!$input->getOption('force')) {
                if (!$input->isInteractive()) {
                    $output->writeln('<error>Use --force to replace an existing lock non-interactively.</error>');

                    return Command::FAILURE;
                }

                /** @var QuestionHelper $helper */
                $helper = $this->getHelper('question');
                $question = new ConfirmationQuestion('Replace current lock? [y/N] ', false);
                if (!$helper->ask($input, $output, $question)) {
                    $output->writeln('Aborted. Existing lock was not changed.');

                    return Command::SUCCESS;
                }
            }
        } else {
            $lock = new BucketLock();
            $lock->setIdentifier(Uuid::v7()->toRfc4122());
            $lock->setInternalBucketId($internalBucketId);
        }

        $this->setLockedMethods($lock, $methods);

        try {
            $this->blobService->addBucketLock($lock);
        } catch (\Exception $e) {
            $output->writeln('<error>'.$e->getMessage().'</error>');

            return Command::FAILURE;
        }

        $output->writeln('<info>Bucket "'.$bucketIdentifier.'" locked.</info>');
        $this->writeLockStatus($output, $lock);

        return Command::SUCCESS;
    }

    /**
     * @return string[]
     */
    private function getSelectedMethods(InputInterface $input): array
    {
        $hasAll = $input->getOption('all');
        $hasReadOnly = $input->getOption('read-only');
        $methods = $input->getOption('method');
        $hasMethods = count($methods) > 0;

        if (((int) $hasAll + (int) $hasReadOnly + (int) $hasMethods) !== 1) {
            throw new \InvalidArgumentException('Specify what to lock: use exactly one of --all, --read-only, or one or more --method options.');
        }

        if ($hasAll) {
            return self::METHODS;
        }

        if ($hasReadOnly) {
            return self::WRITE_METHODS;
        }

        $normalizedMethods = [];
        foreach ($methods as $method) {
            $method = strtoupper($method);
            if (!in_array($method, self::METHODS, true)) {
                throw new \InvalidArgumentException('Invalid method "'.$method.'". Use GET, POST, PATCH, or DELETE.');
            }
            $normalizedMethods[] = $method;
        }

        return array_values(array_intersect(self::METHODS, array_unique($normalizedMethods)));
    }

    /**
     * @param string[] $methods
     */
    private function setLockedMethods(BucketLock $lock, array $methods): void
    {
        $lock->setGetLock(in_array('GET', $methods, true));
        $lock->setPostLock(in_array('POST', $methods, true));
        $lock->setPatchLock(in_array('PATCH', $methods, true));
        $lock->setDeleteLock(in_array('DELETE', $methods, true));
    }

    /**
     * @return string[]
     */
    private function getLockedMethods(BucketLock $lock): array
    {
        $methods = [];
        if ($lock->getGetLock()) {
            $methods[] = 'GET';
        }
        if ($lock->getPostLock()) {
            $methods[] = 'POST';
        }
        if ($lock->getPatchLock()) {
            $methods[] = 'PATCH';
        }
        if ($lock->getDeleteLock()) {
            $methods[] = 'DELETE';
        }

        return $methods;
    }

    /**
     * @param string[] $methods
     */
    private function formatMethods(array $methods): string
    {
        return count($methods) === 0 ? 'none' : implode(',', $methods);
    }

    private function writeLockStatus(OutputInterface $output, BucketLock $lock): void
    {
        $output->writeln('Lock ID: '.$lock->getIdentifier());
        $output->writeln('Locks: '.$this->formatMethods($this->getLockedMethods($lock)));
    }
}
