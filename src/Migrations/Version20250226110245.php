<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;

/**
 * Add column additional_type.
 */
final class Version20250226110245 extends EntityManagerMigration
{
    public function getDescription(): string
    {
        return 'Add table for metadata backup jobs';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE `blob_metadata_backup_jobs`(`identifier` VARCHAR(36), `status` VARCHAR(128), `bucket_id` VARCHAR(128), `started` VARCHAR(36), `finished` VARCHAR(36), `error_id` VARCHAR(2048), `error_message` TEXT, `hash` VARCHAR(48), `file_ref` VARCHAR(4096), PRIMARY KEY(`identifier`))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE `blob_metadata_backup_jobs`');
    }
}
