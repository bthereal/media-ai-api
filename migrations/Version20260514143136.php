<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260514143136 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add title column to content table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE content ADD title VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE content DROP title');
    }
}
