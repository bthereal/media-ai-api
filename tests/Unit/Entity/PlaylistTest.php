<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Content;
use App\Entity\Playlist;
use App\Entity\PlaylistItem;
use PHPUnit\Framework\TestCase;

class PlaylistTest extends TestCase
{
    private function makeContent(string $hashSeed): Content
    {
        return new Content(
            filename: 'video.mp4',
            uploadId: '550e8400-e29b-41d4-a716-446655440000',
            mimeType: 'video/mp4',
            fileSize: 1024,
            fileHash: str_pad($hashSeed, 64, '0', \STR_PAD_LEFT),
        );
    }

    public function testConstructorSetsDefaultsAndVisibility(): void
    {
        $playlist = new Playlist('owner@example.com', 'My Playlist');

        $this->assertSame('owner@example.com', $playlist->getOwnerId());
        $this->assertSame('My Playlist', $playlist->getTitle());
        $this->assertSame('private', $playlist->getVisibility());
        $this->assertFalse($playlist->isPublic());
        $this->assertCount(0, $playlist->getItems());
    }

    public function testIsOwnedBy(): void
    {
        $playlist = new Playlist('owner@example.com', 'Mine');

        $this->assertTrue($playlist->isOwnedBy('owner@example.com'));
        $this->assertFalse($playlist->isOwnedBy('someone-else@example.com'));
    }

    public function testSetTitleAndVisibility(): void
    {
        $playlist = new Playlist('owner@example.com', 'Mine');

        $playlist->setTitle('Renamed');
        $playlist->setVisibility('public');

        $this->assertSame('Renamed', $playlist->getTitle());
        $this->assertTrue($playlist->isPublic());
    }

    public function testNextPositionIsZeroWhenEmpty(): void
    {
        $playlist = new Playlist('owner@example.com', 'Mine');

        $this->assertSame(0, $playlist->nextPosition());
    }

    public function testNextPositionIsOneMoreThanMaxExistingPosition(): void
    {
        $playlist = new Playlist('owner@example.com', 'Mine');
        $playlist->addItem(new PlaylistItem($playlist, $this->makeContent('1'), 0));
        $playlist->addItem(new PlaylistItem($playlist, $this->makeContent('2'), 3));

        $this->assertSame(4, $playlist->nextPosition());
    }

    public function testAddAndRemoveItem(): void
    {
        $playlist = new Playlist('owner@example.com', 'Mine');
        $item = new PlaylistItem($playlist, $this->makeContent('1'), 0);

        $playlist->addItem($item);
        $this->assertCount(1, $playlist->getItems());

        $playlist->removeItem($item);
        $this->assertCount(0, $playlist->getItems());
    }
}
