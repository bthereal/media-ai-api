<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260816090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add owner_id column to content for "My Videos" per-user filtering';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE content ADD owner_id VARCHAR(255) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_content_owner_id ON content (owner_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_content_owner_id');
        $this->addSql('ALTER TABLE content DROP owner_id');
    }
}
