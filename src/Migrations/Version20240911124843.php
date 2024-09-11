<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;

/**
 * Add column additional_type.
 */
final class Version20240911124843 extends EntityManagerMigration
{
    public function getDescription(): string
    {
        return 'Add index on delete_at.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE blob_files ADD INDEX IF NOT EXISTS `idx_delete_at` (`delete_at`)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE blob_files DROP INDEX `idx_delete_at`');
    }
}
