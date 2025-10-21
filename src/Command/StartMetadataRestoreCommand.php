<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Command;

use Dbp\Relay\BlobBundle\Entity\MetadataRestoreJob;
use Dbp\Relay\BlobBundle\Service\BlobService;
use Dbp\Relay\CoreBundle\Exception\ApiError;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\HttpFoundation\Response;

class StartMetadataRestoreCommand extends Command
{
    public function __construct(
        private readonly BlobService $blobService)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('dbp:relay:blob:start-metadata-restore');
        $this
            ->setDescription('Starts a metadata restore for the given bucket.')
            ->addArgument('int-bucket-id', mode: InputArgument::REQUIRED, description: 'The internal bucket ID of the bucket to be restored. If none is given, all are restored.');
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
        $question = new ConfirmationQuestion('You are about to start a restore and potentially overwrite existing data. Are you sure you want to continue? [y/n]: ', false);
        $res = $helper->ask($input, $output, $question);
        if (!$res) {
            return Command::FAILURE;
        }

        $job = new MetadataRestoreJob();
        $this->blobService->setupMetadataRestoreJob($job, $intBucketId);
        $output->writeln('Starting restore for bucket '.$intBucketId.' with jobId '.$job->getIdentifier().' ...');
        try {
            $this->blobService->startMetadataRestore($job);
        } catch (\Exception $e) {
            $job->setStatus(MetadataRestoreJob::JOB_STATUS_ERROR);
            $job->setErrorMessage($e->getMessage());
            if ($e instanceof ApiError) {
                $job->setErrorId($e->getErrorId());
                $this->blobService->finishAndSaveMetadataRestoreJob($job, $intBucketId);
                throw ApiError::withDetails($e->getStatusCode(), $job->getErrorMessage(), $job->getErrorId());
            } else {
                $this->blobService->finishAndSaveMetadataRestoreJob($job, $intBucketId);
                throw ApiError::withDetails(Response::HTTP_INTERNAL_SERVER_ERROR, 'Something went wrong!');
            }
        }
        $this->blobService->finishAndSaveMetadataRestoreJob($job, $intBucketId);
        $output->writeln('Successfully restored bucket '.$intBucketId.'!');

        return 0;
    }
}
