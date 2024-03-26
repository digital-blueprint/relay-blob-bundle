<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;

/**
 * Add column additional_type.
 */
final class Version20240326091207 extends EntityManagerMigration
{
    public function getDescription(): string
    {
        return 'Change type of prefix to VARCHAR(1000), file_name to VARCHAR(255), date_accessed to DATETIME, file_size to BIGINT and additional_type to VARCHAR(255) .';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE blob_files CHANGE `prefix` `prefix` VARCHAR(1000) NOT NULL, CHANGE `file_name` `file_name` VARCHAR(255) NOT NULL, CHANGE `date_accessed` `date_accessed` DATETIME NOT NULL, CHANGE `file_size` `file_size` BIGINT NOT NULL, CHANGE `additional_type` `additional_type` VARCHAR(255)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE blob_files CHANGE `prefix` `prefix` VARCHAR(255) NOT NULL, CHANGE `file_name` `file_name` VARCHAR(1000) NOT NULL, CHANGE `date_accessed` `date_accessed` VARCHAR(1000) NOT NULL, CHANGE `file_size` `file_size` INT NOT NULL, CHANGE `additional_type` `additional_type` LONGTEXT');
    }
}
