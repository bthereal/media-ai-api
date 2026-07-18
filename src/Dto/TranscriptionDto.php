<?php

declare(strict_types=1);

namespace App\Dto;

use App\Entity\VideoTranscription;
use OpenApi\Attributes as OA;

#[OA\Schema(
    required: ['status'],
)]
final readonly class TranscriptionDto
{
    public function __construct(
        #[OA\Property(type: 'string', enum: ['pending', 'processing', 'completed', 'failed'])]
        public string $status,

        #[OA\Property(type: 'string', nullable: true, description: 'Transcribed text — null until status is completed')]
        public ?string $text,

        #[OA\Property(type: 'string', nullable: true, description: 'AI-generated ≤200-char summary — null until embedding step completes')]
        public ?string $summary,

        #[OA\Property(type: 'string', format: 'date-time', nullable: true)]
        public ?string $completedAt,
    ) {
    }

    public static function fromEntity(VideoTranscription $transcription): self
    {
        return new self(
            status: $transcription->getStatus(),
            text: $transcription->getTranscription(),
            summary: $transcription->getSummary(),
            completedAt: $transcription->getCompletedAt()?->format(\DateTimeInterface::ATOM),
        );
    }
}
