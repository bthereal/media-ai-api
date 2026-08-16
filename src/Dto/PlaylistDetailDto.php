<?php

declare(strict_types=1);

namespace App\Dto;

use App\Entity\Playlist;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

#[OA\Schema(
    required: ['ok', 'id', 'title', 'visibility', 'createdAt', 'items'],
)]
final readonly class PlaylistDetailDto
{
    /**
     * @param list<PlaylistItemDto> $items
     */
    public function __construct(
        #[OA\Property(type: 'boolean', example: true)]
        public bool $ok,

        #[OA\Property(type: 'string', format: 'uuid')]
        public string $id,

        #[OA\Property(type: 'string')]
        public string $title,

        #[OA\Property(type: 'string', enum: ['private', 'public'])]
        public string $visibility,

        #[OA\Property(type: 'string', format: 'date-time')]
        public string $createdAt,

        #[OA\Property(type: 'array', items: new OA\Items(ref: new Model(type: PlaylistItemDto::class)))]
        public array $items,
    ) {
    }

    public static function fromEntity(Playlist $playlist): self
    {
        // Sort explicitly rather than trusting collection order — Doctrine's
        // #[ORM\OrderBy] only applies when the collection is first loaded from the
        // DB; in-memory position mutations (e.g. a just-applied reorder) don't
        // re-sort the already-loaded collection.
        $items = $playlist->getItems()->toArray();
        usort($items, static fn ($a, $b) => $a->getPosition() <=> $b->getPosition());

        return new self(
            ok: true,
            id: (string) $playlist->getId(),
            title: $playlist->getTitle(),
            visibility: $playlist->getVisibility(),
            createdAt: $playlist->getCreatedAt()->format(\DateTimeInterface::ATOM),
            items: array_map(PlaylistItemDto::fromEntity(...), $items),
        );
    }
}
