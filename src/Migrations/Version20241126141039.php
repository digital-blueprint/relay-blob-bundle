<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;

/**
 * Add column additional_type.
 */
final class Version20241126141039 extends EntityManagerMigration
{
    public function getDescription(): string
    {
        return 'Add column internal_bucket_id to blob_bucket_locks table.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE blob_bucket_locks ADD `internal_bucket_id` VARCHAR(36) NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE blob_bucket_locks DROP `internal_bucket_id`');
    }
}
