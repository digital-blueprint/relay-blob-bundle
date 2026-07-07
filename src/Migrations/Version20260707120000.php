<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;

/**
 * Change metadata column from JSON to text to avoid double JSON encoding.
 */
final class Version20260707120000 extends EntityManagerMigration
{
    public function getDescription(): string
    {
        return 'Change column metadata from JSON to LONGTEXT to prevent double JSON encoding';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE blob_files CHANGE `metadata` `metadata` LONGTEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE blob_files CHANGE `metadata` `metadata` JSON DEFAULT NULL');
    }
}
