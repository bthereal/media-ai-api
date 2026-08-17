<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Content;
use App\Entity\WatchEvent;
use PHPUnit\Framework\TestCase;

class WatchEventTest extends TestCase
{
    private Content $content;

    protected function setUp(): void
    {
        $this->content = new Content(
            filename: 'video.mp4',
            uploadId: '550e8400-e29b-41d4-a716-446655440000',
            mimeType: 'video/mp4',
            fileSize: 1048576,
            fileHash: str_repeat('a', 64),
        );
    }

    public function testConstructorSetsAllFields(): void
    {
        $event = new WatchEvent($this->content, 'viewer@example.com', 'progress', 12.5);

        $this->assertSame($this->content, $event->getContent());
        $this->assertSame('viewer@example.com', $event->getViewerId());
        $this->assertSame('progress', $event->getEventType());
        $this->assertSame(12.5, $event->getPositionSeconds());
        $this->assertInstanceOf(\DateTimeImmutable::class, $event->getCreatedAt());
    }

    public function testEventTypesConstantListsAllSupportedTypes(): void
    {
        $this->assertSame(['play', 'pause', 'seek', 'progress', 'complete'], WatchEvent::EVENT_TYPES);
    }

    public function testCreatedAtIsSetAtConstruction(): void
    {
        $before = new \DateTimeImmutable();
        $event = new WatchEvent($this->content, 'viewer@example.com', 'play', 0.0);
        $after = new \DateTimeImmutable();

        $this->assertGreaterThanOrEqual($before, $event->getCreatedAt());
        $this->assertLessThanOrEqual($after, $event->getCreatedAt());
    }
}
