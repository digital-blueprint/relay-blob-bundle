<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;

/**
 * Add column additional_type.
 */
final class Version20241107124843 extends EntityManagerMigration
{
    public function getDescription(): string
    {
        return 'Make column metadata DEFAULT NULL';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE blob_files CHANGE `metadata` `metadata` JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE blob_files CHANGE `metadata` `metadata` JSON NOT NULL');
    }
}
