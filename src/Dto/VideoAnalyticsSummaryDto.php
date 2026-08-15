<?php

declare(strict_types=1);

namespace App\Dto;

use OpenApi\Attributes as OA;

#[OA\Schema(
    required: ['contentId', 'title', 'filename', 'views', 'completionRate', 'watchTimeSeconds'],
)]
final readonly class VideoAnalyticsSummaryDto
{
    public function __construct(
        #[OA\Property(type: 'string', format: 'uuid')]
        public string $contentId,

        #[OA\Property(type: 'string', nullable: true)]
        public ?string $title,

        #[OA\Property(type: 'string')]
        public string $filename,

        #[OA\Property(type: 'integer', description: 'Distinct viewers who fired at least one playback event')]
        public int $views,

        #[OA\Property(type: 'number', format: 'float', description: 'Percentage of viewers who fired a "complete" event (0-100)')]
        public float $completionRate,

        #[OA\Property(type: 'number', format: 'float', description: 'Sum of estimated watch time across all viewers, in seconds')]
        public float $watchTimeSeconds,
    ) {
    }
}
