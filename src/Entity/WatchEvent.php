<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\WatchEventRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: WatchEventRepository::class)]
#[ORM\Table(name: 'watch_event')]
#[ORM\Index(columns: ['content_id'], name: 'idx_watch_event_content_id')]
#[ORM\Index(columns: ['content_id', 'viewer_id'], name: 'idx_watch_event_content_viewer')]
class WatchEvent
{
    public const array EVENT_TYPES = ['play', 'pause', 'seek', 'progress', 'complete'];

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Content::class)]
    #[ORM\JoinColumn(name: 'content_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Content $content;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $viewerId;

    #[ORM\Column(type: Types::STRING, length: 20)]
    private string $eventType;

    #[ORM\Column(type: Types::FLOAT)]
    private float $positionSeconds;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(Content $content, string $viewerId, string $eventType, float $positionSeconds)
    {
        $this->content = $content;
        $this->viewerId = $viewerId;
        $this->eventType = $eventType;
        $this->positionSeconds = $positionSeconds;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getContent(): Content
    {
        return $this->content;
    }

    public function getViewerId(): string
    {
        return $this->viewerId;
    }

    public function getEventType(): string
    {
        return $this->eventType;
    }

    public function getPositionSeconds(): float
    {
        return $this->positionSeconds;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
