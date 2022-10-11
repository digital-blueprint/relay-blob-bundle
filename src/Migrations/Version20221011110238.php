<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;

/**
 * generate blob_files.
 */
final class Version20221011110238 extends EntityManagerMigration
{
    public function getDescription(): string
    {
        return 'Creates table blob_files.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE blob_files (identifier VARCHAR(50) NOT NULL, prefix VARCHAR(255) NOT NULL, file_name VARCHAR(50) NOT NULL, bucket_id VARCHAR(50) NOT NULL, date_created DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', last_access DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', retention_duration VARCHAR(50) NOT NULL, idle_retention_duration VARCHAR(50) NOT NULL, content_url VARCHAR(255) NOT NULL, additional_metadata LONGTEXT NOT NULL, PRIMARY KEY(identifier)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE blob_files');
    }
}
