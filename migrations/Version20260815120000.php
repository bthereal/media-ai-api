<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260815120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create playlist and playlist_item tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE playlist (id UUID NOT NULL, owner_id VARCHAR(255) NOT NULL, title VARCHAR(255) NOT NULL, visibility VARCHAR(20) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_playlist_owner_id ON playlist (owner_id)');
        $this->addSql('COMMENT ON COLUMN playlist.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN playlist.created_at IS \'(DC2Type:datetime_immutable)\'');

        $this->addSql('CREATE TABLE playlist_item (id UUID NOT NULL, playlist_id UUID NOT NULL, content_id UUID NOT NULL, position INT NOT NULL, added_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_playlist_item_playlist_id ON playlist_item (playlist_id)');
        $this->addSql('CREATE UNIQUE INDEX uq_playlist_item_playlist_content ON playlist_item (playlist_id, content_id)');
        $this->addSql('ALTER TABLE playlist_item ADD CONSTRAINT FK_PLAYLIST_ITEM_PLAYLIST FOREIGN KEY (playlist_id) REFERENCES playlist (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE playlist_item ADD CONSTRAINT FK_PLAYLIST_ITEM_CONTENT FOREIGN KEY (content_id) REFERENCES content (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('COMMENT ON COLUMN playlist_item.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN playlist_item.playlist_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN playlist_item.content_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN playlist_item.added_at IS \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE playlist_item DROP CONSTRAINT FK_PLAYLIST_ITEM_PLAYLIST');
        $this->addSql('ALTER TABLE playlist_item DROP CONSTRAINT FK_PLAYLIST_ITEM_CONTENT');
        $this->addSql('DROP TABLE playlist_item');
        $this->addSql('DROP TABLE playlist');
    }
}
