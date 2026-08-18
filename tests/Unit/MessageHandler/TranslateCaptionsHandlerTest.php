<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\Entity\Content;
use App\Entity\VideoTranscription;
use App\Message\TranslateCaptionsMessage;
use App\MessageHandler\TranslateCaptionsHandler;
use App\Repository\ContentRepository;
use App\Service\CaptionTranslationService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class TranslateCaptionsHandlerTest extends TestCase
{
    private ContentRepository&MockObject $contentRepo;
    private CaptionTranslationService&MockObject $translationService;
    private EntityManagerInterface&MockObject $em;
    private TranslateCaptionsHandler $handler;

    private const CONTENT_ID = '550e8400-e29b-41d4-a716-446655440000';
    private const UPLOAD_ID = '660e8400-e29b-41d4-a716-446655440001';
    private const FILENAME = 'video.mp4';

    protected function setUp(): void
    {
        $this->contentRepo = $this->createMock(ContentRepository::class);
        $this->translationService = $this->createMock(CaptionTranslationService::class);
        $this->em = $this->createMock(EntityManagerInterface::class);

        $this->handler = new TranslateCaptionsHandler(
            $this->contentRepo,
            $this->translationService,
            $this->em,
            new NullLogger(),
        );
    }

    public function testHandlerSetsTranslationOnSuccess(): void
    {
        $transcription = new VideoTranscription(self::UPLOAD_ID, self::FILENAME);
        $transcription->markCompleted('Hello.', [['start' => 0.0, 'end' => 5.0, 'text' => 'Hello.']]);

        $content = $this->createMock(Content::class);
        $content->method('getTranscription')->willReturn($transcription);

        $this->contentRepo->expects($this->once())->method('find')->with(self::CONTENT_ID)->willReturn($content);

        $translated = [['start' => 0.0, 'end' => 5.0, 'text' => 'Hola.']];
        $this->translationService
            ->expects($this->once())
            ->method('translate')
            ->with($transcription->getSegments(), 'es')
            ->willReturn($translated);

        $this->em->expects($this->once())->method('flush');

        ($this->handler)(new TranslateCaptionsMessage(self::CONTENT_ID, 'es'));

        $this->assertSame($translated, $transcription->getTranslation('es'));
    }

    public function testHandlerSkipsWorkWhenAlreadyCached(): void
    {
        $transcription = new VideoTranscription(self::UPLOAD_ID, self::FILENAME);
        $transcription->markCompleted('Hello.', [['start' => 0.0, 'end' => 5.0, 'text' => 'Hello.']]);
        $transcription->setTranslation('es', [['start' => 0.0, 'end' => 5.0, 'text' => 'Hola.']]);

        $content = $this->createMock(Content::class);
        $content->method('getTranscription')->willReturn($transcription);

        $this->contentRepo->method('find')->willReturn($content);

        $this->translationService->expects($this->never())->method('translate');
        $this->em->expects($this->never())->method('flush');

        ($this->handler)(new TranslateCaptionsMessage(self::CONTENT_ID, 'es'));
    }

    public function testHandlerLetsTranslationFailurePropagateForMessengerRetry(): void
    {
        $transcription = new VideoTranscription(self::UPLOAD_ID, self::FILENAME);
        $transcription->markCompleted('Hello.', [['start' => 0.0, 'end' => 5.0, 'text' => 'Hello.']]);

        $content = $this->createMock(Content::class);
        $content->method('getTranscription')->willReturn($transcription);

        $this->contentRepo->method('find')->willReturn($content);
        $this->translationService->method('translate')->willThrowException(new \RuntimeException('boom'));

        $this->em->expects($this->never())->method('flush');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('boom');

        ($this->handler)(new TranslateCaptionsMessage(self::CONTENT_ID, 'es'));
    }

    public function testHandlerReturnsSilentlyWhenContentNotFound(): void
    {
        $this->contentRepo->method('find')->willReturn(null);

        $this->translationService->expects($this->never())->method('translate');
        $this->em->expects($this->never())->method('flush');

        ($this->handler)(new TranslateCaptionsMessage(self::CONTENT_ID, 'es'));
    }

    public function testHandlerReturnsSilentlyWhenSegmentsAreNull(): void
    {
        $transcription = new VideoTranscription(self::UPLOAD_ID, self::FILENAME);

        $content = $this->createMock(Content::class);
        $content->method('getTranscription')->willReturn($transcription);

        $this->contentRepo->method('find')->willReturn($content);

        $this->translationService->expects($this->never())->method('translate');
        $this->em->expects($this->never())->method('flush');

        ($this->handler)(new TranslateCaptionsMessage(self::CONTENT_ID, 'es'));
    }
}
