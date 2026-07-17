<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\Entity\Content;
use App\Entity\VideoTranscription;
use App\Message\EmbedVideoSummaryMessage;
use App\MessageHandler\EmbedVideoSummaryHandler;
use App\Repository\ContentRepository;
use App\Service\VideoSummaryService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Uid\Uuid;

#[AllowMockObjectsWithoutExpectations]
class EmbedVideoSummaryHandlerTest extends TestCase
{
    private ContentRepository&MockObject $contentRepo;
    private VideoSummaryService&MockObject $summaryService;
    private EmbedVideoSummaryHandler $handler;

    private const CONTENT_ID = '550e8400-e29b-41d4-a716-446655440000';
    private const UPLOAD_ID = '660e8400-e29b-41d4-a716-446655440001';
    private const FILENAME = 'video.mp4';

    protected function setUp(): void
    {
        $this->contentRepo = $this->createMock(ContentRepository::class);
        $this->summaryService = $this->createMock(VideoSummaryService::class);

        $this->handler = new EmbedVideoSummaryHandler(
            $this->contentRepo,
            $this->summaryService,
            new NullLogger(),
        );
    }

    public function testHandlerEmbedsTranscriptOnSuccess(): void
    {
        $transcription = new VideoTranscription(self::UPLOAD_ID, self::FILENAME);
        $transcription->markCompleted('Full transcript text here.');

        $content = $this->createMock(Content::class);
        $content->method('getId')->willReturn(Uuid::fromString(self::CONTENT_ID));
        $content->method('getTitle')->willReturn('My Video');
        $content->method('getFilename')->willReturn(self::FILENAME);
        $content->method('getTranscription')->willReturn($transcription);

        $this->contentRepo->expects($this->once())->method('find')->with(self::CONTENT_ID)->willReturn($content);

        $this->summaryService
            ->expects($this->once())
            ->method('embedAndStore')
            ->with(self::CONTENT_ID, 'Full transcript text here.', 'My Video');

        ($this->handler)(new EmbedVideoSummaryMessage(self::CONTENT_ID));
    }

    public function testHandlerFallsBackToFilenameWhenNoTitle(): void
    {
        $transcription = new VideoTranscription(self::UPLOAD_ID, self::FILENAME);
        $transcription->markCompleted('Transcript.');

        $content = $this->createMock(Content::class);
        $content->method('getId')->willReturn(Uuid::fromString(self::CONTENT_ID));
        $content->method('getTitle')->willReturn(null);
        $content->method('getFilename')->willReturn(self::FILENAME);
        $content->method('getTranscription')->willReturn($transcription);

        $this->contentRepo->method('find')->willReturn($content);

        $this->summaryService
            ->expects($this->once())
            ->method('embedAndStore')
            ->with(self::CONTENT_ID, 'Transcript.', self::FILENAME);

        ($this->handler)(new EmbedVideoSummaryMessage(self::CONTENT_ID));
    }

    public function testHandlerReturnsSilentlyWhenContentNotFound(): void
    {
        $this->contentRepo->method('find')->willReturn(null);

        $this->summaryService->expects($this->never())->method('embedAndStore');

        ($this->handler)(new EmbedVideoSummaryMessage(self::CONTENT_ID));
    }

    public function testHandlerReturnsSilentlyWhenTranscriptionIsNull(): void
    {
        $content = $this->createMock(Content::class);
        $content->method('getTranscription')->willReturn(null);

        $this->contentRepo->method('find')->willReturn($content);

        $this->summaryService->expects($this->never())->method('embedAndStore');

        ($this->handler)(new EmbedVideoSummaryMessage(self::CONTENT_ID));
    }

    public function testHandlerReturnsSilentlyWhenTranscriptionTextIsNull(): void
    {
        $transcription = new VideoTranscription(self::UPLOAD_ID, self::FILENAME);

        $content = $this->createMock(Content::class);
        $content->method('getTranscription')->willReturn($transcription);

        $this->contentRepo->method('find')->willReturn($content);

        $this->summaryService->expects($this->never())->method('embedAndStore');

        ($this->handler)(new EmbedVideoSummaryMessage(self::CONTENT_ID));
    }
}
