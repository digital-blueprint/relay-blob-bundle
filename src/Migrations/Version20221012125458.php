<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Removes idle_retention_duration
 */
final class Version20221012125458 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Removes idle_retention_duration';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE blob_files DROP idle_retention_duration');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE blob_files ADD idle_retention_duration VARCHAR(50) NOT NULL');
    }
}
