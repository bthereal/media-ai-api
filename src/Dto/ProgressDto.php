<?php

declare(strict_types=1);

namespace App\Dto;

use OpenApi\Attributes as OA;

#[OA\Schema(
    required: ['ok'],
)]
final readonly class ProgressDto
{
    public function __construct(
        #[OA\Property(type: 'boolean', example: true)]
        public bool $ok,
        #[OA\Property(type: 'number', format: 'float', nullable: true, description: 'Where the current viewer left off, in seconds — null when there is nothing worth resuming (no prior views, already finished, or too close to the start/end)')]
        public ?float $positionSeconds,
    ) {
    }
}
