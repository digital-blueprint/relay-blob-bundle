<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;

/**
 * Rename extension column to mime_type.
 */
final class Version20231108140920 extends EntityManagerMigration
{
    public function getDescription(): string
    {
        return 'Rename extension column to mime_type.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE blob_files CHANGE extension mime_type VARCHAR(255) NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE blob_files CHANGE mime_type extension VARCHAR(50) NOT NULL');
    }
}
