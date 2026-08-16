<?php

declare(strict_types=1);

namespace App\Dto;

use App\Entity\PlaylistItem;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

#[OA\Schema(
    required: ['id', 'position', 'content'],
)]
final readonly class PlaylistItemDto
{
    public function __construct(
        #[OA\Property(type: 'string', format: 'uuid')]
        public string $id,

        #[OA\Property(type: 'integer')]
        public int $position,

        #[OA\Property(ref: new Model(type: ContentDto::class))]
        public ContentDto $content,
    ) {
    }

    public static function fromEntity(PlaylistItem $item): self
    {
        return new self(
            id: (string) $item->getId(),
            position: $item->getPosition(),
            content: ContentDto::fromEntity($item->getContent()),
        );
    }
}
