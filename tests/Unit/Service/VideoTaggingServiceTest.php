<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\VideoTaggingService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Result\TextResult;

#[AllowMockObjectsWithoutExpectations]
class VideoTaggingServiceTest extends TestCase
{
    private AgentInterface&MockObject $agent;
    private VideoTaggingService $service;

    protected function setUp(): void
    {
        $this->agent = $this->createMock(AgentInterface::class);
        $this->service = new VideoTaggingService($this->agent);
    }

    public function testGenerateTagsReturnsNullCategoryAndEmptyTagsForEmptyTranscript(): void
    {
        $this->agent->expects($this->never())->method('call');

        $result = $this->service->generateTags('   ');

        $this->assertSame(['category' => null, 'tags' => []], $result);
    }

    public function testGenerateTagsParsesValidJsonResponse(): void
    {
        $json = '{"category": "Product Demo", "tags": ["symfony", "ai", "video search"]}';
        $this->agent
            ->expects($this->once())
            ->method('call')
            ->with($this->isInstanceOf(MessageBag::class))
            ->willReturn(new TextResult($json));

        $result = $this->service->generateTags('some transcript');

        $this->assertSame('Product Demo', $result['category']);
        $this->assertSame(['symfony', 'ai', 'video search'], $result['tags']);
    }

    public function testGenerateTagsStripsMarkdownCodeFences(): void
    {
        $json = "```json\n{\"category\": \"Interview\", \"tags\": [\"tag1\"]}\n```";
        $this->agent->method('call')->willReturn(new TextResult($json));

        $result = $this->service->generateTags('transcript');

        $this->assertSame('Interview', $result['category']);
    }

    public function testGenerateTagsThrowsOnUnparseableResponse(): void
    {
        $this->agent->method('call')->willReturn(new TextResult('not json at all'));

        $this->expectException(\RuntimeException::class);

        $this->service->generateTags('transcript');
    }

    public function testGenerateTagsHandlesMissingCategoryGracefully(): void
    {
        $json = '{"tags": ["tag1", "tag2"]}';
        $this->agent->method('call')->willReturn(new TextResult($json));

        $result = $this->service->generateTags('transcript');

        $this->assertNull($result['category']);
        $this->assertSame(['tag1', 'tag2'], $result['tags']);
    }

    public function testGenerateTagsHandlesMissingTagsGracefully(): void
    {
        $json = '{"category": "Tutorial"}';
        $this->agent->method('call')->willReturn(new TextResult($json));

        $result = $this->service->generateTags('transcript');

        $this->assertSame('Tutorial', $result['category']);
        $this->assertSame([], $result['tags']);
    }

    public function testGenerateTagsDropsNonStringTagsAndCapsAtEight(): void
    {
        $json = '{"category": "Tutorial", "tags": ["a", "b", "c", "d", "e", "f", "g", "h", "i", 42]}';
        $this->agent->method('call')->willReturn(new TextResult($json));

        $result = $this->service->generateTags('transcript');

        $this->assertCount(8, $result['tags']);
    }

    public function testGenerateTagsDedupesTags(): void
    {
        $json = '{"category": "Tutorial", "tags": ["ai", "ai", "video"]}';
        $this->agent->method('call')->willReturn(new TextResult($json));

        $result = $this->service->generateTags('transcript');

        $this->assertSame(['ai', 'video'], $result['tags']);
    }
}
