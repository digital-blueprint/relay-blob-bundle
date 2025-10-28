<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;

/**
 * Add column additional_type.
 */
final class Version20251023114125 extends EntityManagerMigration
{
    public function getDescription(): string
    {
        return 'Drop hash from blob_metadata_restore_jobs table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE blob_metadata_restore_jobs DROP `hash`');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE blob_metadata_restore_jobs ADD `hash` VARCHAR(128)');
    }
}
