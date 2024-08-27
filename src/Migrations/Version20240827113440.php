<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;

/**
 * Add column additional_type.
 */
final class Version20240827113440 extends EntityManagerMigration
{
    public function getDescription(): string
    {
        return 'Rename column bucket_id to internal_bucket_id.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE blob_files CHANGE `exists_until` `delete_at` DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE blob_files CHANGE `delete_at` `exists_until` DATETIME DEFAULT NULL');
    }
}
