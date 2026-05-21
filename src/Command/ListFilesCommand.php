<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Command;

use Dbp\Relay\BlobBundle\Helper\BlobUtils;
use Dbp\Relay\BlobBundle\Service\BlobService;
use Dbp\Relay\BlobLibrary\Api\BlobApi;
use Dbp\Relay\CoreBundle\Rest\Query\Filter\FilterTreeBuilder;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ListFilesCommand extends Command
{
    public function __construct(
        private readonly BlobService $blobService)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('dbp:relay:blob:files:list');
        $this
            ->setDescription('Lists files in a blob bucket. Shows a summary by default; use --list for paginated detail.')
            ->addArgument('bucketIdentifier', InputArgument::REQUIRED, 'The public bucket identifier.')
            ->addOption('prefix', null, InputOption::VALUE_REQUIRED, 'Filter by prefix.')
            ->addOption('prefix-starts-with', null, InputOption::VALUE_NONE, 'Treat --prefix as a starts-with filter instead of an exact match.')
            ->addOption('list', null, InputOption::VALUE_NONE, 'Show a paginated list of files instead of a summary.')
            ->addOption('page', null, InputOption::VALUE_REQUIRED, 'Page number (only with --list).', 1)
            ->addOption('per-page', null, InputOption::VALUE_REQUIRED, 'Items per page (only with --list).', 30)
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: "table" or "json" (only with --list).', 'table');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $bucketIdentifier = $input->getArgument('bucketIdentifier');
        $prefix = $input->getOption('prefix');
        $prefixStartsWith = $input->getOption('prefix-starts-with');
        $isList = $input->getOption('list');
        $page = (int) $input->getOption('page');
        $perPage = (int) $input->getOption('per-page');
        $format = $input->getOption('format');

        if (!$isList) {
            foreach (['page', 'per-page', 'format'] as $opt) {
                if ($input->getOption($opt) !== $this->getDefinition()->getOption($opt)->getDefault()) {
                    $output->writeln('<comment>Option --'.$opt.' is ignored without --list.</comment>');
                }
            }
        }

        if ($isList && !in_array($format, ['table', 'json'], true)) {
            $output->writeln('<error>Invalid format "'.$format.'". Use "table" or "json".</error>');

            return Command::FAILURE;
        }

        $filter = $this->buildFilter($prefix, $prefixStartsWith);
        $options = [BlobApi::DISABLE_OUTPUT_VALIDATION_OPTION => true];

        try {
            if ($isList) {
                return $this->executeList($output, $bucketIdentifier, $filter, $options, $page, $perPage, $format);
            }

            return $this->executeSummary($output, $bucketIdentifier, $prefix, $filter, $options);
        } catch (\Exception $e) {
            $output->writeln('<error>'.$e->getMessage().'</error>');

            return Command::FAILURE;
        }
    }

    private function executeList(OutputInterface $output, string $bucketIdentifier, ?object $filter,
        array $options, int $page, int $perPage, string $format): int
    {
        $files = $this->blobService->getFiles($bucketIdentifier, $filter, $options, $page, $perPage);

        $rows = [];
        foreach ($files as $fileData) {
            $rows[] = [
                'identifier' => $fileData->getIdentifier(),
                'fileName' => $fileData->getFileName(),
                'fileSize' => $fileData->getFileSize(),
                'prefix' => $fileData->getPrefix(),
                'dateCreated' => $fileData->getDateCreated()?->format(\DateTimeInterface::ATOM),
                'deleteAt' => $fileData->getDeleteAt()?->format(\DateTimeInterface::ATOM),
            ];
        }

        if ($format === 'json') {
            $output->writeln(json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $table = new Table($output);
            $table->setHeaders(['identifier', 'fileName', 'fileSize', 'prefix', 'dateCreated', 'deleteAt']);
            foreach ($rows as $row) {
                $row['fileSize'] = BlobUtils::formatBytes($row['fileSize']);
                $table->addRow(array_values($row));
            }
            $table->render();
            $output->writeln('Page '.$page.', '.count($rows).' item(s).');
        }

        return Command::SUCCESS;
    }

    private function executeSummary(OutputInterface $output, string $bucketIdentifier, ?string $prefix,
        ?object $filter, array $options): int
    {
        $count = 0;
        $page = 1;
        $perPage = 1000;
        do {
            $files = $this->blobService->getFiles($bucketIdentifier, $filter, $options, $page, $perPage);
            $count += count($files);
            ++$page;
        } while (count($files) === $perPage);

        $output->writeln('Bucket: '.$bucketIdentifier);
        $output->writeln('Prefix: '.($prefix ?? '(all)'));
        $output->writeln('Files:  '.$count);
        $output->writeln('');
        $output->writeln('Use --list to show the actual file list.');

        return Command::SUCCESS;
    }

    private function buildFilter(?string $prefix, bool $prefixStartsWith): ?\Dbp\Relay\CoreBundle\Rest\Query\Filter\Filter
    {
        if ($prefix === null) {
            return null;
        }

        $builder = FilterTreeBuilder::create();
        if ($prefixStartsWith) {
            $builder->iStartsWith('prefix', $prefix);
        } else {
            $builder->equals('prefix', $prefix);
        }

        return $builder->createFilter();
    }
}
