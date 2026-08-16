<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ContentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: ContentRepository::class)]
#[ORM\Table(name: 'content')]
class Content
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private Uuid $id;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $filename;

    #[ORM\Column(type: Types::STRING, length: 36)]
    private string $uploadId;

    #[ORM\Column(type: Types::STRING, length: 100)]
    private string $mimeType;

    #[ORM\Column(type: Types::INTEGER)]
    private int $fileSize;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $duration;

    #[ORM\Column(type: Types::STRING, length: 64)]
    private string $fileHash;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $title = null;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $hasThumbnail = false;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    #[ORM\OneToOne(targetEntity: VideoTranscription::class, cascade: ['persist', 'remove'])]
    #[ORM\JoinColumn(name: 'transcription_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?VideoTranscription $transcription = null;

    public function __construct(
        string $filename,
        string $uploadId,
        string $mimeType,
        int $fileSize,
        string $fileHash,
        ?float $duration = null,
    ) {
        $this->filename = $filename;
        $this->uploadId = $uploadId;
        $this->mimeType = $mimeType;
        $this->fileSize = $fileSize;
        $this->fileHash = $fileHash;
        $this->duration = $duration;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getFilename(): string
    {
        return $this->filename;
    }

    public function getUploadId(): string
    {
        return $this->uploadId;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function getFileSize(): int
    {
        return $this->fileSize;
    }

    public function getDuration(): ?float
    {
        return $this->duration;
    }

    public function getFileHash(): string
    {
        return $this->fileHash;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): void
    {
        $this->title = $title;
    }

    public function hasThumbnail(): bool
    {
        return $this->hasThumbnail;
    }

    public function setHasThumbnail(bool $hasThumbnail): void
    {
        $this->hasThumbnail = $hasThumbnail;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getDeletedAt(): ?\DateTimeImmutable
    {
        return $this->deletedAt;
    }

    public function archive(): void
    {
        $this->deletedAt = new \DateTimeImmutable();
    }

    /**
     * Un-archives a previously-deleted Content — used when an identical file
     * (matched by hash) is uploaded again, so it's reactivated in place rather
     * than creating a duplicate row and a duplicate search embedding.
     */
    public function unarchive(): void
    {
        $this->deletedAt = null;
    }

    public function getTranscription(): ?VideoTranscription
    {
        return $this->transcription;
    }

    public function setTranscription(VideoTranscription $transcription): void
    {
        $this->transcription = $transcription;
    }
}
