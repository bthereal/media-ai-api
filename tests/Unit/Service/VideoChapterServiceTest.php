<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\VideoChapterService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Result\TextResult;

#[AllowMockObjectsWithoutExpectations]
class VideoChapterServiceTest extends TestCase
{
    private AgentInterface&MockObject $agent;
    private VideoChapterService $service;

    private const SEGMENTS = [
        ['start' => 0.0, 'end' => 10.0, 'text' => 'Welcome to the show.'],
        ['start' => 10.0, 'end' => 40.0, 'text' => 'Lets talk about pricing.'],
    ];

    protected function setUp(): void
    {
        $this->agent = $this->createMock(AgentInterface::class);
        $this->service = new VideoChapterService($this->agent);
    }

    public function testGenerateChaptersReturnsEmptyArrayForEmptySegments(): void
    {
        $this->agent->expects($this->never())->method('call');

        $result = $this->service->generateChapters([], 40.0);

        $this->assertSame([], $result);
    }

    public function testGenerateChaptersParsesValidJsonResponse(): void
    {
        $json = '[{"title": "Intro", "startSeconds": 0, "endSeconds": 10}, {"title": "Pricing", "startSeconds": 10, "endSeconds": 40}]';
        $this->agent
            ->expects($this->once())
            ->method('call')
            ->with($this->isInstanceOf(MessageBag::class))
            ->willReturn(new TextResult($json));

        $result = $this->service->generateChapters(self::SEGMENTS, 40.0);

        $this->assertSame(
            [
                ['title' => 'Intro', 'startSeconds' => 0.0, 'endSeconds' => 10.0],
                ['title' => 'Pricing', 'startSeconds' => 10.0, 'endSeconds' => 40.0],
            ],
            $result,
        );
    }

    public function testGenerateChaptersStripsMarkdownCodeFences(): void
    {
        $json = "```json\n[{\"title\": \"Intro\", \"startSeconds\": 0, \"endSeconds\": 10}]\n```";
        $this->agent->method('call')->willReturn(new TextResult($json));

        $result = $this->service->generateChapters(self::SEGMENTS, 40.0);

        $this->assertCount(1, $result);
        $this->assertSame('Intro', $result[0]['title']);
    }

    public function testGenerateChaptersThrowsOnUnparseableResponse(): void
    {
        $this->agent->method('call')->willReturn(new TextResult('not json at all'));

        $this->expectException(\RuntimeException::class);

        $this->service->generateChapters(self::SEGMENTS, 40.0);
    }

    public function testGenerateChaptersDropsEntriesMissingRequiredFields(): void
    {
        $json = '[{"title": "Intro", "startSeconds": 0, "endSeconds": 10}, {"title": "Bad entry"}]';
        $this->agent->method('call')->willReturn(new TextResult($json));

        $result = $this->service->generateChapters(self::SEGMENTS, 40.0);

        $this->assertCount(1, $result);
        $this->assertSame('Intro', $result[0]['title']);
    }

    public function testGenerateChaptersFallsBackToGeneratedTitleWhenMissing(): void
    {
        $json = '[{"startSeconds": 0, "endSeconds": 10}]';
        $this->agent->method('call')->willReturn(new TextResult($json));

        $result = $this->service->generateChapters(self::SEGMENTS, 40.0);

        $this->assertSame('Chapter 1', $result[0]['title']);
    }

    public function testGenerateChaptersClampsTimestampsToDuration(): void
    {
        $json = '[{"title": "Overrun", "startSeconds": -5, "endSeconds": 999}]';
        $this->agent->method('call')->willReturn(new TextResult($json));

        $result = $this->service->generateChapters(self::SEGMENTS, 40.0);

        $this->assertSame(0.0, $result[0]['startSeconds']);
        $this->assertSame(40.0, $result[0]['endSeconds']);
    }

    public function testGenerateChaptersSortsByStartSeconds(): void
    {
        $json = '[{"title": "Second", "startSeconds": 20, "endSeconds": 40}, {"title": "First", "startSeconds": 0, "endSeconds": 20}]';
        $this->agent->method('call')->willReturn(new TextResult($json));

        $result = $this->service->generateChapters(self::SEGMENTS, 40.0);

        $this->assertSame('First', $result[0]['title']);
        $this->assertSame('Second', $result[1]['title']);
    }

    public function testGenerateChaptersExtendsLastChapterToTrueDurationWhenModelUndershoots(): void
    {
        // Regression: observed live on a 1621s video where the model reported a last
        // chapter ending at 691s, leaving the final ~55% of the video with no chapter.
        $json = '[{"title": "Intro", "startSeconds": 0, "endSeconds": 200}, {"title": "Main topic", "startSeconds": 200, "endSeconds": 691}]';
        $this->agent->method('call')->willReturn(new TextResult($json));

        $result = $this->service->generateChapters(self::SEGMENTS, 1621.4);

        $this->assertSame(1621.4, $result[array_key_last($result)]['endSeconds']);
        // Earlier chapters must be untouched by the extension
        $this->assertSame(200.0, $result[0]['endSeconds']);
    }

    public function testGenerateChaptersDoesNotShrinkLastChapterWhenModelAlreadyReachesDuration(): void
    {
        $json = '[{"title": "Whole thing", "startSeconds": 0, "endSeconds": 40}]';
        $this->agent->method('call')->willReturn(new TextResult($json));

        $result = $this->service->generateChapters(self::SEGMENTS, 40.0);

        $this->assertSame(40.0, $result[0]['endSeconds']);
    }
}
