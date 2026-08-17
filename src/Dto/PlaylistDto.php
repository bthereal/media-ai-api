<?php

declare(strict_types=1);

namespace App\Dto;

use App\Entity\Playlist;
use OpenApi\Attributes as OA;

#[OA\Schema(
    required: ['id', 'title', 'visibility', 'itemCount', 'createdAt'],
)]
final readonly class PlaylistDto
{
    public function __construct(
        #[OA\Property(type: 'string', format: 'uuid')]
        public string $id,
        #[OA\Property(type: 'string')]
        public string $title,
        #[OA\Property(type: 'string', enum: ['private', 'public'])]
        public string $visibility,
        #[OA\Property(type: 'integer')]
        public int $itemCount,
        #[OA\Property(type: 'string', format: 'date-time')]
        public string $createdAt,
    ) {
    }

    public static function fromEntity(Playlist $playlist): self
    {
        return new self(
            id: (string) $playlist->getId(),
            title: $playlist->getTitle(),
            visibility: $playlist->getVisibility(),
            itemCount: count($playlist->getItems()),
            createdAt: $playlist->getCreatedAt()->format(\DateTimeInterface::ATOM),
        );
    }
}
