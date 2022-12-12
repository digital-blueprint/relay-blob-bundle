<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;

/**
 * Add notify_email to blob_files.
 */
final class Version20221212123956 extends EntityManagerMigration
{
    public function getDescription(): string
    {
        return 'Add notify_email to blob_files.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE blob_files ADD notify_email VARCHAR(255)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE blob_files DROP notify_email');
    }
}
