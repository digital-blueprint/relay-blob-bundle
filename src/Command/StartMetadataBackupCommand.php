<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Command;

use Dbp\Relay\BlobBundle\Entity\MetadataBackupJob;
use Dbp\Relay\BlobBundle\Service\BlobService;
use Dbp\Relay\CoreBundle\Exception\ApiError;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\HttpFoundation\Response;

class StartMetadataBackupCommand extends Command
{
    public function __construct(
        private readonly BlobService $blobService)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('dbp:relay:blob:start-metadata-backup');
        $this
            ->setDescription('Starts a metadata backup for the given bucket.')
            ->addArgument('int-bucket-id', mode: InputArgument::REQUIRED, description: 'The internal bucket ID of the bucket to be backupped. If none is given, all are backupped.');
    }

    /**
     * @throws \Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $intBucketId = $input->getArgument('int-bucket-id');

        /**
         * @var QuestionHelper $helper
         */
        $helper = $this->getHelper('question');
        $question = new ConfirmationQuestion('You are about to start a backup and potentially overwrite existing backups. Are you sure you want to continue? [y/n]: ', false);
        $res = $helper->ask($input, $output, $question);
        if (!$res) {
            return Command::FAILURE;
        }

        $job = new MetadataBackupJob();
        $this->blobService->setupMetadataBackupJob($job, $intBucketId);
        $output->writeln('Starting backup for bucket '.$intBucketId.' with jobId '.$job->getIdentifier().' ...');
        try {
            $this->blobService->startMetadataBackup($job);
        } catch (\Exception $e) {
            $job->setStatus(MetadataBackupJob::JOB_STATUS_ERROR);
            $job->setErrorMessage($e->getMessage());
            if ($e instanceof ApiError) {
                $job->setErrorId($e->getErrorId());
                $this->blobService->finishAndSaveMetadataBackupJob($job, $intBucketId);
                throw ApiError::withDetails($e->getStatusCode(), $job->getErrorMessage(), $job->getErrorId());
            } else {
                $this->blobService->finishAndSaveMetadataBackupJob($job, $intBucketId);
                throw ApiError::withDetails(Response::HTTP_INTERNAL_SERVER_ERROR, 'Something went wrong!');
            }
        }
        $this->blobService->finishAndSaveMetadataBackupJob($job, $intBucketId);

        return 0;
    }
}
