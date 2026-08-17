<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260817140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add content.thumbnail_candidate_count and video_transcription.requested_caption_languages';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE content ADD thumbnail_candidate_count INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE video_transcription ADD requested_caption_languages JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE content DROP thumbnail_candidate_count');
        $this->addSql('ALTER TABLE video_transcription DROP requested_caption_languages');
    }
}
