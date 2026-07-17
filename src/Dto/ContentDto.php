<?php

declare(strict_types=1);

namespace App\Dto;

use App\Entity\Content;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'ContentResponse',
    required: ['ok', 'id', 'filename', 'uploadId', 'mimeType', 'fileSize', 'hasThumbnail', 'createdAt'],
)]
final readonly class ContentDto
{
    public function __construct(
        #[OA\Property(type: 'boolean', example: true)]
        public bool $ok,

        #[OA\Property(type: 'string', format: 'uuid')]
        public string $id,

        #[OA\Property(type: 'string', nullable: true, description: 'User-defined title; null until set')]
        public ?string $title,

        #[OA\Property(type: 'string', example: 'video.mp4')]
        public string $filename,

        #[OA\Property(type: 'string', format: 'uuid')]
        public string $uploadId,

        #[OA\Property(type: 'string', example: 'video/mp4')]
        public string $mimeType,

        #[OA\Property(type: 'integer', description: 'File size in bytes')]
        public int $fileSize,

        #[OA\Property(type: 'number', format: 'float', nullable: true, description: 'Duration in seconds')]
        public ?float $duration,

        #[OA\Property(type: 'boolean', description: 'Whether a JPEG thumbnail has been generated')]
        public bool $hasThumbnail,

        #[OA\Property(type: 'string', format: 'date-time')]
        public string $createdAt,

        #[OA\Property(type: 'string', format: 'date-time', nullable: true, description: 'Set when the video has been archived')]
        public ?string $deletedAt,

        #[OA\Property(nullable: true, ref: new Model(type: TranscriptionDto::class))]
        public ?TranscriptionDto $transcription,
    ) {
    }

    public static function fromEntity(Content $content): self
    {
        return new self(
            ok: true,
            id: (string) $content->getId(),
            title: $content->getTitle(),
            filename: $content->getFilename(),
            uploadId: $content->getUploadId(),
            mimeType: $content->getMimeType(),
            fileSize: $content->getFileSize(),
            duration: $content->getDuration(),
            hasThumbnail: $content->hasThumbnail(),
            createdAt: $content->getCreatedAt()->format(\DateTimeInterface::ATOM),
            deletedAt: $content->getDeletedAt()?->format(\DateTimeInterface::ATOM),
            transcription: null !== $content->getTranscription()
                ? TranscriptionDto::fromEntity($content->getTranscription())
                : null,
        );
    }
}
