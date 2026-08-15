<?php

declare(strict_types=1);

namespace App\Dto;

use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

#[OA\Schema(
    required: ['ok', 'contentId', 'views', 'completionRate', 'totalWatchTimeSeconds', 'averageWatchTimeSeconds', 'retentionCurve'],
)]
final readonly class VideoAnalyticsDto
{
    /**
     * @param list<RetentionPointDto> $retentionCurve
     */
    public function __construct(
        #[OA\Property(type: 'boolean', example: true)]
        public bool $ok,

        #[OA\Property(type: 'string', format: 'uuid')]
        public string $contentId,

        #[OA\Property(type: 'integer', description: 'Distinct viewers who fired at least one playback event')]
        public int $views,

        #[OA\Property(type: 'number', format: 'float', description: 'Percentage of viewers who fired a "complete" event (0-100)')]
        public float $completionRate,

        #[OA\Property(type: 'number', format: 'float', description: 'Sum of estimated watch time across all viewers, in seconds')]
        public float $totalWatchTimeSeconds,

        #[OA\Property(type: 'number', format: 'float', description: 'Average estimated watch time per viewer, in seconds')]
        public float $averageWatchTimeSeconds,

        #[OA\Property(type: 'number', format: 'float', nullable: true, description: 'Average furthest position reached across viewers, in seconds — a proxy for where people stop watching')]
        public ?float $averageDropOffSeconds,

        #[OA\Property(type: 'array', items: new OA\Items(ref: new Model(type: RetentionPointDto::class)), description: 'Percentage of viewers still watching at each 10% mark; empty when the video has no known duration')]
        public array $retentionCurve,
    ) {
    }
}
