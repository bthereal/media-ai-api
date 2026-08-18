<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tool;

use App\Entity\Content;
use App\Service\VideoSearchService;
use App\Tool\VideoSimilaritySearch;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[AllowMockObjectsWithoutExpectations]
class VideoSimilaritySearchTest extends TestCase
{
    private VideoSearchService&MockObject $videoSearchService;
    private VideoSimilaritySearch $tool;

    protected function setUp(): void
    {
        $this->videoSearchService = $this->createMock(VideoSearchService::class);
        $this->tool = new VideoSimilaritySearch($this->videoSearchService);
    }

    public function testInvokeReturnsFallbackMessageWhenNoHits(): void
    {
        $this->videoSearchService->method('search')->willReturn([]);

        $this->assertSame('No matching videos found in the library.', ($this->tool)('some query'));
    }

    public function testInvokeFormatsHitsWithTitleIdAndExcerpt(): void
    {
        $content = $this->createMock(Content::class);
        $content->method('getId')->willReturn(Uuid::fromString('550e8400-e29b-41d4-a716-446655440000'));
        $content->method('getTitle')->willReturn('My Video');

        $this->videoSearchService->method('search')->willReturn([
            ['content' => $content, 'text' => 'A short transcript excerpt.'],
        ]);

        $output = ($this->tool)('some query');

        $this->assertStringContainsString('**My Video**', $output);
        $this->assertStringContainsString('550e8400-e29b-41d4-a716-446655440000', $output);
        $this->assertStringContainsString('A short transcript excerpt.', $output);
    }

    public function testInvokeTruncatesLongExcerptsTo300Chars(): void
    {
        $content = $this->createMock(Content::class);
        $content->method('getId')->willReturn(Uuid::fromString('550e8400-e29b-41d4-a716-446655440000'));
        $content->method('getTitle')->willReturn('My Video');

        $this->videoSearchService->method('search')->willReturn([
            ['content' => $content, 'text' => str_repeat('x', 400)],
        ]);

        $output = ($this->tool)('some query');

        $this->assertStringContainsString(str_repeat('x', 300) . '…', $output);
        $this->assertStringNotContainsString(str_repeat('x', 301), $output);
    }
}
