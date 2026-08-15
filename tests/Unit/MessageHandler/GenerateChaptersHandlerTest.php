<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\Entity\Content;
use App\Entity\VideoTranscription;
use App\Message\GenerateChaptersMessage;
use App\MessageHandler\GenerateChaptersHandler;
use App\Repository\ContentRepository;
use App\Service\VideoChapterService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class GenerateChaptersHandlerTest extends TestCase
{
    private ContentRepository&MockObject $contentRepo;
    private VideoChapterService&MockObject $chapterService;
    private EntityManagerInterface&MockObject $em;
    private GenerateChaptersHandler $handler;

    private const CONTENT_ID = '550e8400-e29b-41d4-a716-446655440000';
    private const UPLOAD_ID = '660e8400-e29b-41d4-a716-446655440001';
    private const FILENAME = 'video.mp4';

    protected function setUp(): void
    {
        $this->contentRepo = $this->createMock(ContentRepository::class);
        $this->chapterService = $this->createMock(VideoChapterService::class);
        $this->em = $this->createMock(EntityManagerInterface::class);

        $this->handler = new GenerateChaptersHandler(
            $this->contentRepo,
            $this->chapterService,
            $this->em,
            new NullLogger(),
        );
    }

    public function testHandlerSetsChaptersOnSuccess(): void
    {
        $transcription = new VideoTranscription(self::UPLOAD_ID, self::FILENAME);
        $transcription->markCompleted('Full transcript.', [['start' => 0.0, 'end' => 10.0, 'text' => 'Hi.']]);

        $content = $this->createMock(Content::class);
        $content->method('getDuration')->willReturn(10.0);
        $content->method('getTranscription')->willReturn($transcription);

        $this->contentRepo->expects($this->once())->method('find')->with(self::CONTENT_ID)->willReturn($content);

        $chapters = [['title' => 'Intro', 'startSeconds' => 0.0, 'endSeconds' => 10.0]];
        $this->chapterService
            ->expects($this->once())
            ->method('generateChapters')
            ->with($transcription->getSegments(), 10.0)
            ->willReturn($chapters);

        $this->em->expects($this->once())->method('flush');

        ($this->handler)(new GenerateChaptersMessage(self::CONTENT_ID));

        $this->assertSame($chapters, $transcription->getChapters());
    }

    public function testHandlerReturnsSilentlyWhenContentNotFound(): void
    {
        $this->contentRepo->method('find')->willReturn(null);

        $this->chapterService->expects($this->never())->method('generateChapters');
        $this->em->expects($this->never())->method('flush');

        ($this->handler)(new GenerateChaptersMessage(self::CONTENT_ID));
    }

    public function testHandlerReturnsSilentlyWhenTranscriptionIsNull(): void
    {
        $content = $this->createMock(Content::class);
        $content->method('getTranscription')->willReturn(null);

        $this->contentRepo->method('find')->willReturn($content);

        $this->chapterService->expects($this->never())->method('generateChapters');
        $this->em->expects($this->never())->method('flush');

        ($this->handler)(new GenerateChaptersMessage(self::CONTENT_ID));
    }

    public function testHandlerReturnsSilentlyWhenSegmentsAreNull(): void
    {
        $transcription = new VideoTranscription(self::UPLOAD_ID, self::FILENAME);

        $content = $this->createMock(Content::class);
        $content->method('getTranscription')->willReturn($transcription);

        $this->contentRepo->method('find')->willReturn($content);

        $this->chapterService->expects($this->never())->method('generateChapters');
        $this->em->expects($this->never())->method('flush');

        ($this->handler)(new GenerateChaptersMessage(self::CONTENT_ID));
    }
}
