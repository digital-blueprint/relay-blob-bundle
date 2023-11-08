<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;

/**
 * Add column additional_type.
 */
final class Version20231108130325 extends EntityManagerMigration
{
    public function getDescription(): string
    {
        return 'Add column additional_type.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE blob_files ADD additional_type LONGTEXT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE blob_files DROP additional_type');
    }
}
