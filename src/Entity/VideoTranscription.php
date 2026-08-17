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

    /**
     * @var list<array{start: float, end: float, text: string}>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $segments = null;

    /**
     * Null until the post-transcription chapter-generation step has run; an empty
     * array means it ran but produced no chapters (e.g. too little transcript).
     *
     * @var list<array{title: string, startSeconds: float, endSeconds: float}>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $chapters = null;

    /**
     * Raw language name as detected by Whisper (e.g. "english") — null until completed.
     */
    #[ORM\Column(type: Types::STRING, length: 50, nullable: true)]
    private ?string $language = null;

    /**
     * Cached AI-translated captions, keyed by ISO 639-1 target code.
     *
     * @var array<string, list<array{start: float, end: float, text: string}>>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $translations = null;

    /**
     * Null until the post-transcription tagging step has run; an empty array
     * means it ran but produced no tags.
     *
     * @var list<string>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $tags = null;

    /**
     * Single AI-assigned category (e.g. "Product Demo") — null until tagged.
     */
    #[ORM\Column(type: Types::STRING, length: 100, nullable: true)]
    private ?string $category = null;

    /**
     * Caption languages the uploader asked to have generated eagerly (in addition
     * to whatever a viewer might request lazily later) — null/empty means none
     * were requested at upload time.
     *
     * @var list<string>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $requestedCaptionLanguages = null;

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

    public function setSummary(?string $summary): void
    {
        $this->summary = $summary;
    }

    public function markProcessing(): void
    {
        $this->status = 'processing';
    }

    /**
     * @param list<array{start: float, end: float, text: string}> $segments
     */
    public function markCompleted(string $transcription, array $segments = [], ?string $language = null): void
    {
        $this->status = 'completed';
        $this->transcription = $transcription;
        $this->segments = $segments;
        $this->language = $language;
        $this->completedAt = new \DateTimeImmutable();
    }

    public function getLanguage(): ?string
    {
        return $this->language;
    }

    /**
     * @return list<array{start: float, end: float, text: string}>|null
     */
    public function getTranslation(string $langCode): ?array
    {
        return $this->translations[$langCode] ?? null;
    }

    /**
     * @param list<array{start: float, end: float, text: string}> $segments
     */
    public function setTranslation(string $langCode, array $segments): void
    {
        $this->translations ??= [];
        $this->translations[$langCode] = $segments;
    }

    /**
     * @return list<array{start: float, end: float, text: string}>|null
     */
    public function getSegments(): ?array
    {
        return $this->segments;
    }

    /**
     * @return list<array{title: string, startSeconds: float, endSeconds: float}>|null
     */
    public function getChapters(): ?array
    {
        return $this->chapters;
    }

    /**
     * @param list<array{title: string, startSeconds: float, endSeconds: float}> $chapters
     */
    public function setChapters(array $chapters): void
    {
        $this->chapters = $chapters;
    }

    /**
     * @return list<string>|null
     */
    public function getTags(): ?array
    {
        return $this->tags;
    }

    /**
     * @param list<string> $tags
     */
    public function setTagsAndCategory(array $tags, ?string $category): void
    {
        $this->tags = $tags;
        $this->category = $category;
    }

    public function getCategory(): ?string
    {
        return $this->category;
    }

    /**
     * @return list<string>
     */
    public function getRequestedCaptionLanguages(): array
    {
        return $this->requestedCaptionLanguages ?? [];
    }

    /**
     * @param list<string> $languages
     */
    public function setRequestedCaptionLanguages(array $languages): void
    {
        $this->requestedCaptionLanguages = $languages;
    }

    public function markFailed(string $errorMessage): void
    {
        $this->status = 'failed';
        $this->errorMessage = $errorMessage;
    }
}
