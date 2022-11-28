<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobConnectorFilesystemBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20221128073704 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Change not null policies for additional metadata.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE blob_files ALTER COLUMN additional_metadata additional_metadata LONGTEXT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE blob_files ALTER COLUMN additional_metadata additional_metadata LONGTEXT NOT NULL');
    }
}
