<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260814200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add segments (raw Whisper timing) and chapters (AI-generated) JSON columns to video_transcription';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE video_transcription ADD segments JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE video_transcription ADD chapters JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE video_transcription DROP segments');
        $this->addSql('ALTER TABLE video_transcription DROP chapters');
    }
}
