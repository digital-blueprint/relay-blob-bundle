<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Command;

use Dbp\Relay\BlobBundle\Helper\BlobUtils;
use Dbp\Relay\BlobBundle\Service\BlobService;
use Dbp\Relay\BlobLibrary\Api\BlobApi;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class GetFileInfoCommand extends Command
{
    public function __construct(
        private readonly BlobService $blobService)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('dbp:relay:blob:files:info');
        $this
            ->setDescription('Displays metadata for a file stored in blob storage.')
            ->addArgument('identifier', InputArgument::REQUIRED, 'The identifier (UUID) of the file.')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: "table" or "json".', 'table');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $identifier = $input->getArgument('identifier');
        $format = $input->getOption('format');

        if (!in_array($format, ['table', 'json'], true)) {
            $output->writeln('<error>Invalid format "'.$format.'". Use "table" or "json".</error>');

            return Command::FAILURE;
        }

        try {
            $fileData = $this->blobService->getFileData($identifier, [
                BlobService::UPDATE_LAST_ACCESS_TIMESTAMP_OPTION => false,
                BlobApi::DISABLE_OUTPUT_VALIDATION_OPTION => true,
            ]);
        } catch (\Exception $e) {
            $output->writeln('<error>Failed to retrieve file metadata: '.$e->getMessage().'</error>');

            return Command::FAILURE;
        }

        $info = [
            'identifier' => $fileData->getIdentifier(),
            'fileName' => $fileData->getFileName(),
            'mimeType' => $fileData->getMimeType(),
            'fileSize' => $fileData->getFileSize(),
            'fileHash' => $fileData->getFileHash(),
            'bucketId' => $fileData->getBucketId(),
            'prefix' => $fileData->getPrefix(),
            'type' => $fileData->getType(),
            'metadata' => $fileData->getMetadata(),
            'dateCreated' => $fileData->getDateCreated()?->format(\DateTimeInterface::ATOM),
            'dateModified' => $fileData->getDateModified()?->format(\DateTimeInterface::ATOM),
            'dateAccessed' => $fileData->getDateAccessed()?->format(\DateTimeInterface::ATOM),
            'deleteAt' => $fileData->getDeleteAt()?->format(\DateTimeInterface::ATOM),
        ];

        if ($format === 'json') {
            $output->writeln(json_encode($info, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $metadata = $info['metadata'];
            unset($info['metadata']);

            $table = new Table($output);
            $table->setHeaders(['Field', 'Value']);
            foreach ($info as $field => $value) {
                $displayValue = $field === 'fileSize' && is_int($value) ? BlobUtils::formatBytes($value) : $value;
                $table->addRow([$field, $displayValue ?? '']);
            }

            if ($metadata !== null) {
                $decoded = json_decode($metadata, true);
                $pretty = $decoded !== null
                    ? json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                    : $metadata;
                $table->addRow(['metadata', $pretty]);
            } else {
                $table->addRow(['metadata', '']);
            }

            $table->render();
        }

        return Command::SUCCESS;
    }
}
