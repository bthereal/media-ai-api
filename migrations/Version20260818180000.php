<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260818180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop video_transcription.requested_caption_languages — captions are now translated lazily on first request for all defined target languages, not pre-selected at upload time';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE video_transcription DROP requested_caption_languages');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE video_transcription ADD requested_caption_languages JSON DEFAULT NULL');
    }
}
