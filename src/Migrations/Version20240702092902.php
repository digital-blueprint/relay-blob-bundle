<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;

/**
 * Add column additional_type.
 */
final class Version20240702092902 extends EntityManagerMigration
{
    public function getDescription(): string
    {
        return 'Change name of additionalMetadata to metadata and additionalType to type.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE blob_files CHANGE `additional_metadata` `metadata` JSON NOT NULL, CHANGE `additional_type` `type` VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE blob_files CHANGE `metadata` `additional_metadata` JSON NOT NULL, CHANGE `type` `additional_type` VARCHAR(255) DEFAULT NULL');
    }
}
