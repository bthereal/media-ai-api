<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Content;
use App\Entity\Playlist;
use App\Entity\PlaylistItem;
use PHPUnit\Framework\TestCase;

class PlaylistItemTest extends TestCase
{
    public function testConstructorSetsAllFields(): void
    {
        $playlist = new Playlist('owner@example.com', 'Mine');
        $content = new Content(
            filename: 'video.mp4',
            uploadId: '550e8400-e29b-41d4-a716-446655440000',
            mimeType: 'video/mp4',
            fileSize: 1024,
            fileHash: str_repeat('a', 64),
        );

        $item = new PlaylistItem($playlist, $content, 2);

        $this->assertSame($playlist, $item->getPlaylist());
        $this->assertSame($content, $item->getContent());
        $this->assertSame(2, $item->getPosition());
        $this->assertInstanceOf(\DateTimeImmutable::class, $item->getAddedAt());
    }

    public function testSetPosition(): void
    {
        $playlist = new Playlist('owner@example.com', 'Mine');
        $content = new Content(
            filename: 'video.mp4',
            uploadId: '550e8400-e29b-41d4-a716-446655440000',
            mimeType: 'video/mp4',
            fileSize: 1024,
            fileHash: str_repeat('b', 64),
        );
        $item = new PlaylistItem($playlist, $content, 0);

        $item->setPosition(5);

        $this->assertSame(5, $item->getPosition());
    }
}
