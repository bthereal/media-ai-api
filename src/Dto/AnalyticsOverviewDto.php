<?php

declare(strict_types=1);

namespace App\Dto;

use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

#[OA\Schema(
    required: ['ok', 'totalVideos', 'totalViews', 'totalWatchTimeSeconds', 'averageCompletionRate', 'videos'],
)]
final readonly class AnalyticsOverviewDto
{
    /**
     * @param list<VideoAnalyticsSummaryDto> $videos
     */
    public function __construct(
        #[OA\Property(type: 'boolean', example: true)]
        public bool $ok,

        #[OA\Property(type: 'integer', description: 'Total non-archived content items in the library')]
        public int $totalVideos,

        #[OA\Property(type: 'integer', description: 'Sum of distinct viewers across all videos')]
        public int $totalViews,

        #[OA\Property(type: 'number', format: 'float', description: 'Sum of estimated watch time across all videos, in seconds')]
        public float $totalWatchTimeSeconds,

        #[OA\Property(type: 'number', format: 'float', description: 'Average completion rate across videos with at least one view (0-100)')]
        public float $averageCompletionRate,

        #[OA\Property(type: 'array', items: new OA\Items(ref: new Model(type: VideoAnalyticsSummaryDto::class)), description: 'Per-video summary, sorted by views descending')]
        public array $videos,
    ) {
    }
}
