<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260814170100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create watch_event table for playback analytics (play/pause/seek/progress/complete events)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE watch_event (id UUID NOT NULL, content_id UUID NOT NULL, viewer_id VARCHAR(255) NOT NULL, event_type VARCHAR(20) NOT NULL, position_seconds DOUBLE PRECISION NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_watch_event_content_id ON watch_event (content_id)');
        $this->addSql('CREATE INDEX idx_watch_event_content_viewer ON watch_event (content_id, viewer_id)');
        $this->addSql('ALTER TABLE watch_event ADD CONSTRAINT FK_WATCH_EVENT_CONTENT FOREIGN KEY (content_id) REFERENCES content (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('COMMENT ON COLUMN watch_event.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN watch_event.content_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN watch_event.created_at IS \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE watch_event DROP CONSTRAINT FK_WATCH_EVENT_CONTENT');
        $this->addSql('DROP TABLE watch_event');
    }
}
