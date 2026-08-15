<?php

declare(strict_types=1);

namespace App\Dto;

use OpenApi\Attributes as OA;

#[OA\Schema(
    required: ['percent', 'retentionRate'],
)]
final readonly class RetentionPointDto
{
    public function __construct(
        #[OA\Property(type: 'integer', description: 'Position through the video, as a percentage (0, 10, 20, ... 100)')]
        public int $percent,

        #[OA\Property(type: 'number', format: 'float', description: 'Percentage of viewers who reached this point (0-100)')]
        public float $retentionRate,
    ) {
    }
}
