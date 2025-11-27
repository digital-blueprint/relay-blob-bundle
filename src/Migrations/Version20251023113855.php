<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;

/**
 * Add column additional_type.
 */
final class Version20251023113855 extends EntityManagerMigration
{
    public function getDescription(): string
    {
        return 'Add metadata_backup_job_id to blob_metadata_restore_jobs table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE blob_metadata_restore_jobs ADD `metadata_backup_job_id` VARCHAR(36) NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE blob_metadata_restore_jobs DROP `metadata_backup_job_id`');
    }
}
