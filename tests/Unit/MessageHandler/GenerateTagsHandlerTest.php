<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\Entity\Content;
use App\Entity\VideoTranscription;
use App\Message\GenerateTagsMessage;
use App\MessageHandler\GenerateTagsHandler;
use App\Repository\ContentRepository;
use App\Service\VideoTaggingService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class GenerateTagsHandlerTest extends TestCase
{
    private ContentRepository&MockObject $contentRepo;
    private VideoTaggingService&MockObject $taggingService;
    private EntityManagerInterface&MockObject $em;
    private GenerateTagsHandler $handler;

    private const CONTENT_ID = '550e8400-e29b-41d4-a716-446655440000';
    private const UPLOAD_ID = '660e8400-e29b-41d4-a716-446655440001';
    private const FILENAME = 'video.mp4';

    protected function setUp(): void
    {
        $this->contentRepo = $this->createMock(ContentRepository::class);
        $this->taggingService = $this->createMock(VideoTaggingService::class);
        $this->em = $this->createMock(EntityManagerInterface::class);

        $this->handler = new GenerateTagsHandler(
            $this->contentRepo,
            $this->taggingService,
            $this->em,
            new NullLogger(),
        );
    }

    public function testHandlerSetsTagsAndCategoryOnSuccess(): void
    {
        $transcription = new VideoTranscription(self::UPLOAD_ID, self::FILENAME);
        $transcription->markCompleted('Full transcript.', []);

        $content = $this->createMock(Content::class);
        $content->method('getTranscription')->willReturn($transcription);

        $this->contentRepo->expects($this->once())->method('find')->with(self::CONTENT_ID)->willReturn($content);

        $this->taggingService
            ->expects($this->once())
            ->method('generateTags')
            ->with('Full transcript.')
            ->willReturn(['category' => 'Tutorial', 'tags' => ['ai', 'video']]);

        $this->em->expects($this->once())->method('flush');

        ($this->handler)(new GenerateTagsMessage(self::CONTENT_ID));

        $this->assertSame('Tutorial', $transcription->getCategory());
        $this->assertSame(['ai', 'video'], $transcription->getTags());
    }

    public function testHandlerReturnsSilentlyWhenContentNotFound(): void
    {
        $this->contentRepo->method('find')->willReturn(null);

        $this->taggingService->expects($this->never())->method('generateTags');
        $this->em->expects($this->never())->method('flush');

        ($this->handler)(new GenerateTagsMessage(self::CONTENT_ID));
    }

    public function testHandlerReturnsSilentlyWhenTranscriptionIsNull(): void
    {
        $content = $this->createMock(Content::class);
        $content->method('getTranscription')->willReturn(null);

        $this->contentRepo->method('find')->willReturn($content);

        $this->taggingService->expects($this->never())->method('generateTags');
        $this->em->expects($this->never())->method('flush');

        ($this->handler)(new GenerateTagsMessage(self::CONTENT_ID));
    }

    public function testHandlerReturnsSilentlyWhenTranscriptionTextIsNull(): void
    {
        $transcription = new VideoTranscription(self::UPLOAD_ID, self::FILENAME);

        $content = $this->createMock(Content::class);
        $content->method('getTranscription')->willReturn($transcription);

        $this->contentRepo->method('find')->willReturn($content);

        $this->taggingService->expects($this->never())->method('generateTags');
        $this->em->expects($this->never())->method('flush');

        ($this->handler)(new GenerateTagsMessage(self::CONTENT_ID));
    }
}
