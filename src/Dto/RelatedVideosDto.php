<?php

declare(strict_types=1);

namespace App\Dto;

use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

#[OA\Schema(
    required: ['ok', 'items'],
)]
final readonly class RelatedVideosDto
{
    /**
     * @param list<ContentDto> $items
     */
    public function __construct(
        #[OA\Property(type: 'boolean', example: true)]
        public bool $ok,

        #[OA\Property(type: 'array', items: new OA\Items(ref: new Model(type: ContentDto::class)), description: 'Semantically similar videos, nearest first — empty until the video has a completed, embedded transcript')]
        public array $items,
    ) {
    }
}
