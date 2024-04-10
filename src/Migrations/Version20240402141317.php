<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;

/**
 * Add column additional_type.
 */
final class Version20240402141317 extends EntityManagerMigration
{
    public function getDescription(): string
    {
        return 'Add table for bucket filesize sums';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE `blob_bucket_sizes`(`identifier` VARCHAR(36), `current_bucket_size` BIGINT, PRIMARY KEY(`identifier`))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE `blob_bucket_sizes`');
    }
}
