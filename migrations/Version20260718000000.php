<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260718000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Replace full unique constraint on file_hash with a partial unique index (excludes soft-deleted rows)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS uq_content_file_hash');
        $this->addSql('CREATE UNIQUE INDEX uq_content_file_hash ON content (file_hash) WHERE deleted_at IS NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS uq_content_file_hash');
        $this->addSql('ALTER TABLE content ADD CONSTRAINT uq_content_file_hash UNIQUE (file_hash)');
    }
}
