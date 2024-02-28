<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;

/**
 * Add column additional_type.
 */
final class Version20240227145440 extends EntityManagerMigration
{
    public function getDescription(): string
    {
        return 'Change type of column identifier from VARCHAR to BINARY.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE blob_files CHANGE `identifier` `identifier` BINARY(16) NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE blob_files CHANGE `identifier` `identifier` VARCHAR(50) NOT NULL');
    }
}
