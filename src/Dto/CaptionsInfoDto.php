<?php

declare(strict_types=1);

namespace App\Dto;

use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

#[OA\Schema(
    required: ['nativeLanguage', 'availableTranslations'],
)]
final readonly class CaptionsInfoDto
{
    /**
     * @param list<LanguageOptionDto> $availableTranslations
     */
    public function __construct(
        #[OA\Property(ref: new Model(type: LanguageOptionDto::class), description: 'Always available once transcription has completed — served directly from stored segments, no AI call')]
        public LanguageOptionDto $nativeLanguage,

        #[OA\Property(type: 'array', items: new OA\Items(ref: new Model(type: LanguageOptionDto::class)), description: 'Curated set of languages that can be requested — translated and cached on first request')]
        public array $availableTranslations,
    ) {
    }
}
