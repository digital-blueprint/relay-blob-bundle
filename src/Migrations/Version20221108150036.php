<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;

/**
 * Add extension column to blob_files.
 */
final class Version20221108150036 extends EntityManagerMigration
{
    public function getDescription(): string
    {
        return 'Add extension column to blob_files.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE blob_files ADD extension VARCHAR(50) NOT NULL, CHANGE date_created date_created DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE last_access last_access DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE blob_files DROP extension, CHANGE date_created date_created DATETIME NOT NULL, CHANGE last_access last_access DATETIME NOT NULL');
    }
}
