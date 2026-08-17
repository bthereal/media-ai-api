<?php

declare(strict_types=1);

namespace App\Dto;

use OpenApi\Attributes as OA;

#[OA\Schema(
    required: ['code', 'label'],
)]
final readonly class LanguageOptionDto
{
    public function __construct(
        #[OA\Property(type: 'string', example: 'es', description: 'ISO 639-1 code')]
        public string $code,
        #[OA\Property(type: 'string', example: 'Spanish')]
        public string $label,
    ) {
    }
}
