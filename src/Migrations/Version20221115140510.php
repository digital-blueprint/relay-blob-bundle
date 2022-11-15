<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;

/**
 * Refactor retention duration to exists_until.
 */
final class Version20221115140510 extends EntityManagerMigration
{
    public function getDescription(): string
    {
        return 'Refactor retention duration to exists_until.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE blob_files ADD exists_until DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', DROP retention_duration');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE blob_files ADD retention_duration VARCHAR(50) NOT NULL, DROP exists_until');
    }
}
