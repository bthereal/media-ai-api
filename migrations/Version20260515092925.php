<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260515092925 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add summary column to video_transcription table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE video_transcription ADD summary TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE video_transcription DROP summary');
    }
}
