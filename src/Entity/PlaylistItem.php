<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'playlist_item')]
#[ORM\UniqueConstraint(name: 'uq_playlist_item_playlist_content', columns: ['playlist_id', 'content_id'])]
#[ORM\Index(columns: ['playlist_id'], name: 'idx_playlist_item_playlist_id')]
class PlaylistItem
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Playlist::class, inversedBy: 'items')]
    #[ORM\JoinColumn(name: 'playlist_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Playlist $playlist;

    #[ORM\ManyToOne(targetEntity: Content::class)]
    #[ORM\JoinColumn(name: 'content_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Content $content;

    #[ORM\Column(type: Types::INTEGER)]
    private int $position;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $addedAt;

    public function __construct(Playlist $playlist, Content $content, int $position)
    {
        $this->playlist = $playlist;
        $this->content = $content;
        $this->position = $position;
        $this->addedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getPlaylist(): Playlist
    {
        return $this->playlist;
    }

    public function getContent(): Content
    {
        return $this->content;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): void
    {
        $this->position = $position;
    }

    public function getAddedAt(): \DateTimeImmutable
    {
        return $this->addedAt;
    }
}
