<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Remove content url from table
 */
final class Version20221012131001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove content url from table.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE blob_files DROP content_url');
    }

    public function down(Schema $schema): void
    {
       $this->addSql('ALTER TABLE blob_files ADD content_url VARCHAR(255) NOT NULL');
    }
}
