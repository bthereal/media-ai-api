<?php

declare(strict_types=1);

namespace App\Dto;

use OpenApi\Attributes as OA;

#[OA\Schema(
    required: ['title', 'startSeconds', 'endSeconds'],
)]
final readonly class ChapterDto
{
    public function __construct(
        #[OA\Property(type: 'string', example: 'Intro')]
        public string $title,
        #[OA\Property(type: 'number', format: 'float')]
        public float $startSeconds,
        #[OA\Property(type: 'number', format: 'float')]
        public float $endSeconds,
    ) {
    }

    /**
     * @param array{title: string, startSeconds: float, endSeconds: float} $chapter
     */
    public static function fromArray(array $chapter): self
    {
        return new self(
            title: $chapter['title'],
            startSeconds: $chapter['startSeconds'],
            endSeconds: $chapter['endSeconds'],
        );
    }
}
