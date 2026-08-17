<?php

declare(strict_types=1);

namespace App\Dto;

use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

#[OA\Schema(
    required: ['ok', 'items', 'total', 'page', 'perPage', 'totalPages', 'hasNext', 'hasPrev', 'availableCategories'],
)]
final readonly class ContentListDto
{
    /**
     * @param ContentDto[]  $items
     * @param list<string>  $availableCategories
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
        #[OA\Property(type: 'array', items: new OA\Items(type: 'string'), description: 'Distinct categories present across the whole (unfiltered) library, for building facet filters')]
        public array $availableCategories,
    ) {
    }
}
