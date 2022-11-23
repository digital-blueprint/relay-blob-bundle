<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobConnectorFilesystemBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Change not null policies for additional metadata.
 */
final class Version20221123132039 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Change not null policies for additional metadata.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE blob_files CHANGE additional_metadata additional_metadata LONGTEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE blob_files CHANGE additional_metadata additional_metadata LONGTEXT NOT NULL');
    }
}
