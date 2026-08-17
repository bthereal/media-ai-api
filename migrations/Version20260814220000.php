<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260814220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add language and translations columns to video_transcription for auto-generated/translated captions';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE video_transcription ADD language VARCHAR(50) DEFAULT NULL');
        $this->addSql('ALTER TABLE video_transcription ADD translations JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE video_transcription DROP language');
        $this->addSql('ALTER TABLE video_transcription DROP translations');
    }
}
