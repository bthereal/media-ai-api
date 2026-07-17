<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'video_transcription')]
#[ORM\Index(columns: ['upload_id'], name: 'idx_video_transcription_upload_id')]
class VideoTranscription
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private Uuid $id;

    #[ORM\Column(type: Types::STRING, length: 36)]
    private string $uploadId;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $filename;

    #[ORM\Column(type: Types::STRING, length: 20)]
    private string $status;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $transcription = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $errorMessage = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $summary = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    public function __construct(string $uploadId, string $filename)
    {
        $this->uploadId = $uploadId;
        $this->filename = $filename;
        $this->status = 'pending';
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUploadId(): string
    {
        return $this->uploadId;
    }

    public function getFilename(): string
    {
        return $this->filename;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getTranscription(): ?string
    {
        return $this->transcription;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function getSummary(): ?string
    {
        return $this->summary;
    }

    public function setSummary(string $summary): void
    {
        $this->summary = $summary;
    }

    public function markProcessing(): void
    {
        $this->status = 'processing';
    }

    public function markCompleted(string $transcription): void
    {
        $this->status = 'completed';
        $this->transcription = $transcription;
        $this->completedAt = new \DateTimeImmutable();
    }

    public function markFailed(string $errorMessage): void
    {
        $this->status = 'failed';
        $this->errorMessage = $errorMessage;
    }
}
