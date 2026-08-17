<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260817090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Replace users.active boolean with users.deactivated_at timestamp';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD deactivated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('UPDATE users SET deactivated_at = now() WHERE active = false');
        $this->addSql('ALTER TABLE users DROP active');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD active BOOLEAN DEFAULT true NOT NULL');
        $this->addSql('UPDATE users SET active = (deactivated_at IS NULL)');
        $this->addSql('ALTER TABLE users DROP deactivated_at');
    }
}
