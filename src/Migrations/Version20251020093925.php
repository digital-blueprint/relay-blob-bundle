<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;

/**
 * Add column additional_type.
 */
final class Version20251020093925 extends EntityManagerMigration
{
    public function getDescription(): string
    {
        return 'Add table for metadata restore jobs';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE `blob_metadata_restore_jobs`(`identifier` VARCHAR(36), `status` VARCHAR(128), `bucket_id` VARCHAR(128), `started` VARCHAR(36), `finished` VARCHAR(36), `error_id` VARCHAR(2048), `error_message` TEXT, `hash` VARCHAR(128), PRIMARY KEY(`identifier`))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE `blob_metadata_restore_jobs`');
    }
}
