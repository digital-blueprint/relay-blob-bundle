<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;

/**
 *  Add fileSize to blob_files table.
 */
final class Version20221116100631 extends EntityManagerMigration
{
    public function getDescription(): string
    {
        return 'Add fileSize to blob_files table.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE blob_files ADD file_size INT NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE blob_files DROP file_size');
    }
}
