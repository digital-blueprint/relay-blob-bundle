<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;

/**
 * Add column additional_type.
 */
final class Version20241119110250 extends EntityManagerMigration
{
    public function getDescription(): string
    {
        return 'Add table for bucket locks';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE `blob_bucket_locks`(`identifier` VARCHAR(36), `post_lock` BOOLEAN, `get_lock` BOOLEAN, `patch_lock` BOOLEAN, `delete_lock` BOOLEAN, PRIMARY KEY(`identifier`))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE `blob_bucket_locks`');
    }
}
