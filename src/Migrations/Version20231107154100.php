<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;

/**
 * Rename last_access column to date_accessed
 */
final class Version20231107154100 extends EntityManagerMigration
{
    public function getDescription(): string
    {
        return 'Rename last_access column to date_accessed.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE blob_files ADD date_modified DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE blob_files DROP date_modified');
    }
}
