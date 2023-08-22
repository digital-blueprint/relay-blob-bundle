<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;

/**
 * Increase file_name column size of blob_files to 1000 characters.
 */
final class Version20230822081500 extends EntityManagerMigration
{
    public function getDescription(): string
    {
        return 'Increase file_name column size of blob_files to 1000 characters.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE blob_files CHANGE file_name file_name VARCHAR(1000) NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE blob_files CHANGE file_name file_name VARCHAR(50) NOT NULL');
    }
}
