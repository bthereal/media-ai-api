<?php

declare(strict_types=1);

namespace App\Dto;

use App\Entity\VideoTranscription;
use App\Service\CaptionLanguages;
use Nelmio\ApiDocBundle\Attribute\Model;
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

        #[OA\Property(
            type: 'array',
            items: new OA\Items(ref: new Model(type: ChapterDto::class)),
            nullable: true,
            description: 'AI-generated chapters — null until the post-transcription chapter step has run, empty if it ran but produced none',
        )]
        public ?array $chapters,

        #[OA\Property(ref: new Model(type: CaptionsInfoDto::class), nullable: true, description: 'Null until transcription has completed with timed segments')]
        public ?CaptionsInfoDto $captions,

        #[OA\Property(type: 'array', items: new OA\Items(type: 'string'), nullable: true, description: 'AI-extracted topic tags — null until the post-transcription tagging step has run, empty if it ran but produced none')]
        public ?array $tags,

        #[OA\Property(type: 'string', nullable: true, description: 'AI-assigned category (e.g. "Product Demo") — null until tagged')]
        public ?string $category,
    ) {
    }

    public static function fromEntity(VideoTranscription $transcription): self
    {
        $chapters = $transcription->getChapters();
        $language = $transcription->getLanguage();
        $segments = $transcription->getSegments();

        $captions = null;
        if (null !== $language && null !== $segments && [] !== $segments) {
            $nativeCode = CaptionLanguages::codeForWhisperLanguage($language);
            $captions = new CaptionsInfoDto(
                nativeLanguage: new LanguageOptionDto($nativeCode, CaptionLanguages::labelFor($nativeCode)),
                availableTranslations: array_map(
                    static fn (string $code) => new LanguageOptionDto($code, CaptionLanguages::labelFor($code)),
                    array_values(array_filter(CaptionLanguages::TRANSLATION_TARGETS, static fn (string $code) => $code !== $nativeCode)),
                ),
            );
        }

        return new self(
            status: $transcription->getStatus(),
            text: $transcription->getTranscription(),
            summary: $transcription->getSummary(),
            completedAt: $transcription->getCompletedAt()?->format(\DateTimeInterface::ATOM),
            chapters: null !== $chapters ? array_map(ChapterDto::fromArray(...), $chapters) : null,
            captions: $captions,
            tags: $transcription->getTags(),
            category: $transcription->getCategory(),
        );
    }
}
