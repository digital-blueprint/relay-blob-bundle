<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;

/**
 * Add column additional_type.
 */
final class Version20250319153306 extends EntityManagerMigration
{
    public function getDescription(): string
    {
        return 'Resize column hash of blob_metadata_backup_jobs table to 128 chars.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE blob_metadata_backup_jobs MODIFY `hash` VARCHAR(128)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE blob_metadata_backup_jobs MODIFY `hash` VARCHAR(48)');
    }
}
