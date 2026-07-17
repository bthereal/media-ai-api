<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260514141518 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add has_thumbnail column to content table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE content ADD has_thumbnail BOOLEAN NOT NULL DEFAULT FALSE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE content DROP has_thumbnail');
    }
}
