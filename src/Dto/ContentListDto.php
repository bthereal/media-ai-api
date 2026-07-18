<?php

declare(strict_types=1);

namespace App\Dto;

use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

#[OA\Schema(
    required: ['ok', 'items', 'total', 'page', 'perPage', 'totalPages', 'hasNext', 'hasPrev'],
)]
final readonly class ContentListDto
{
    /**
     * @param ContentDto[] $items
     */
    public function __construct(
        #[OA\Property(type: 'boolean', example: true)]
        public bool $ok,

        #[OA\Property(type: 'array', items: new OA\Items(ref: new Model(type: ContentDto::class)))]
        public array $items,

        #[OA\Property(type: 'integer', example: 42, description: 'Total number of content items')]
        public int $total,

        #[OA\Property(type: 'integer', example: 1)]
        public int $page,

        #[OA\Property(type: 'integer', example: 12)]
        public int $perPage,

        #[OA\Property(type: 'integer', example: 4)]
        public int $totalPages,

        #[OA\Property(type: 'boolean')]
        public bool $hasNext,

        #[OA\Property(type: 'boolean')]
        public bool $hasPrev,
    ) {
    }
}
