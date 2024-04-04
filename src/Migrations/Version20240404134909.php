<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;

/**
 * Add column additional_type.
 */
final class Version20240404134909 extends EntityManagerMigration
{
    public function getDescription(): string
    {
        return 'Add index on prefix and internal_bucket_id.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE blob_files ADD INDEX IF NOT EXISTS `idx_date_created` (`date_created`)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE blob_files DROP INDEX `idx_date_created`');
    }
}
