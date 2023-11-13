<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;

/**
 * Rename extension column to mime_type.
 */
final class Version20231113100711 extends EntityManagerMigration
{
    public function getDescription(): string
    {
        return 'Rename extension column to mime_type.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE blob_files CHANGE additional_metadata additional_metadata JSON NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE blob_files CHANGE additional_metadata additional_metadata LONGTEXT NOT NULL');
    }
}
