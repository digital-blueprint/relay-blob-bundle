<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;

/**
 * Add column additional_type.
 */
final class Version20240626144610 extends EntityManagerMigration
{
    public function getDescription(): string
    {
        return 'Change type of exists_until to DATETIME DEFAULT NULL.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE blob_files CHANGE `exists_until` `exists_until` DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE blob_files CHANGE `exists_until` `exists_until` DATETIME NOT NULL');
    }
}
