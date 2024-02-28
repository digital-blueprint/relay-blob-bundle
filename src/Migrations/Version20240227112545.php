<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;

/**
 * Add column additional_type.
 */
final class Version20240227112545 extends EntityManagerMigration
{
    public function getDescription(): string
    {
        return 'Rename column bucket_id to internal_bucket_id.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE blob_files CHANGE `bucket_id` `internal_bucket_id` VARCHAR(36) NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE blob_files CHANGE `internal_bucket_id` `bucket_id` VARCHAR(36) NOT NULL');
    }
}
