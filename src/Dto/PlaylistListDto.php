<?php

declare(strict_types=1);

namespace App\Dto;

use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

#[OA\Schema(
    required: ['ok', 'items'],
)]
final readonly class PlaylistListDto
{
    /**
     * @param list<PlaylistDto> $items
     */
    public function __construct(
        #[OA\Property(type: 'boolean', example: true)]
        public bool $ok,
        #[OA\Property(type: 'array', items: new OA\Items(ref: new Model(type: PlaylistDto::class)))]
        public array $items,
    ) {
    }
}
