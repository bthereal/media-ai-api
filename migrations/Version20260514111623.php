<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260514111623 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create content table with file metadata and link to video_transcription';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE content (id UUID NOT NULL, filename VARCHAR(255) NOT NULL, upload_id VARCHAR(36) NOT NULL, mime_type VARCHAR(100) NOT NULL, file_size INT NOT NULL, duration DOUBLE PRECISION DEFAULT NULL, file_hash VARCHAR(64) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, transcription_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_FEC530A9F678E194 ON content (transcription_id)');
        $this->addSql('CREATE UNIQUE INDEX uq_content_file_hash ON content (file_hash)');
        $this->addSql('ALTER TABLE content ADD CONSTRAINT FK_FEC530A9F678E194 FOREIGN KEY (transcription_id) REFERENCES video_transcription (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('COMMENT ON COLUMN content.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN content.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN content.transcription_id IS \'(DC2Type:uuid)\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE content DROP CONSTRAINT FK_FEC530A9F678E194');
        $this->addSql('DROP TABLE content');
    }
}
