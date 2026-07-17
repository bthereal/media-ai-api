<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260514092837 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create video_transcription table for storing upload transcription results';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE video_transcription (id UUID NOT NULL, upload_id VARCHAR(36) NOT NULL, filename VARCHAR(255) NOT NULL, status VARCHAR(20) NOT NULL, transcription TEXT DEFAULT NULL, error_message TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, completed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_video_transcription_upload_id ON video_transcription (upload_id)');
        $this->addSql('COMMENT ON COLUMN video_transcription.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN video_transcription.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN video_transcription.completed_at IS \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE video_transcription');
    }
}
