<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;

/**
 * Rename last_access column to date_accessed
 */
final class Version20231107145500 extends EntityManagerMigration
{
    public function getDescription(): string
    {
        return 'Rename last_access column to date_accessed.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE blob_files CHANGE last_access date_accessed VARCHAR(1000) NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE blob_files CHANGE date_accessed last_access VARCHAR(1000) NOT NULL');
    }
}
