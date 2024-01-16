<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;

/**
 * Add column additional_type.
 */
final class Version20240104143015 extends EntityManagerMigration
{
    public function getDescription(): string
    {
        return 'Add column file_hash.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE blob_files ADD file_hash VARCHAR(64), ADD metadata_hash VARCHAR(64)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE blob_files DROP file_hash, DROP metadata_hash');
    }
}
