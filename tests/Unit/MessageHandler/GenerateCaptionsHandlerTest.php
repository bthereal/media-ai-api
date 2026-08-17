<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\Entity\Content;
use App\Entity\VideoTranscription;
use App\Message\GenerateCaptionsMessage;
use App\MessageHandler\GenerateCaptionsHandler;
use App\Repository\ContentRepository;
use App\Service\CaptionTranslationService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class GenerateCaptionsHandlerTest extends TestCase
{
    private ContentRepository&MockObject $contentRepo;
    private CaptionTranslationService&MockObject $translationService;
    private EntityManagerInterface&MockObject $em;
    private GenerateCaptionsHandler $handler;

    private const CONTENT_ID = '550e8400-e29b-41d4-a716-446655440000';
    private const UPLOAD_ID = '660e8400-e29b-41d4-a716-446655440001';
    private const FILENAME = 'video.mp4';

    protected function setUp(): void
    {
        $this->contentRepo = $this->createMock(ContentRepository::class);
        $this->translationService = $this->createMock(CaptionTranslationService::class);
        $this->em = $this->createMock(EntityManagerInterface::class);

        $this->handler = new GenerateCaptionsHandler(
            $this->contentRepo,
            $this->translationService,
            $this->em,
            new NullLogger(),
        );
    }

    private function completedTranscription(array $requestedLanguages): VideoTranscription
    {
        $transcription = new VideoTranscription(self::UPLOAD_ID, self::FILENAME);
        $transcription->markCompleted('Hello world.', [['start' => 0.0, 'end' => 1.0, 'text' => 'Hello world.']], 'english');
        $transcription->setRequestedCaptionLanguages($requestedLanguages);

        return $transcription;
    }

    public function testTranslatesEachRequestedLanguageAndFlushesOnce(): void
    {
        $transcription = $this->completedTranscription(['es', 'fr']);

        $content = $this->createMock(Content::class);
        $content->method('getTranscription')->willReturn($transcription);
        $this->contentRepo->expects($this->once())->method('find')->with(self::CONTENT_ID)->willReturn($content);

        $this->translationService
            ->expects($this->exactly(2))
            ->method('translate')
            ->willReturnMap([
                [$transcription->getSegments(), 'es', [['start' => 0.0, 'end' => 1.0, 'text' => 'Hola mundo.']]],
                [$transcription->getSegments(), 'fr', [['start' => 0.0, 'end' => 1.0, 'text' => 'Bonjour le monde.']]],
            ]);

        $this->em->expects($this->once())->method('flush');

        ($this->handler)(new GenerateCaptionsMessage(self::CONTENT_ID));

        $this->assertNotNull($transcription->getTranslation('es'));
        $this->assertNotNull($transcription->getTranslation('fr'));
    }

    public function testSkipsNativeLanguage(): void
    {
        $transcription = $this->completedTranscription(['en']);

        $content = $this->createMock(Content::class);
        $content->method('getTranscription')->willReturn($transcription);
        $this->contentRepo->method('find')->willReturn($content);

        $this->translationService->expects($this->never())->method('translate');

        ($this->handler)(new GenerateCaptionsMessage(self::CONTENT_ID));

        $this->assertNull($transcription->getTranslation('en'));
    }

    public function testSkipsAlreadyTranslatedLanguage(): void
    {
        $transcription = $this->completedTranscription(['es']);
        $transcription->setTranslation('es', [['start' => 0.0, 'end' => 1.0, 'text' => 'Ya traducido.']]);

        $content = $this->createMock(Content::class);
        $content->method('getTranscription')->willReturn($transcription);
        $this->contentRepo->method('find')->willReturn($content);

        $this->translationService->expects($this->never())->method('translate');

        ($this->handler)(new GenerateCaptionsMessage(self::CONTENT_ID));
    }

    public function testContinuesAndFlushesWhenOneTranslationFails(): void
    {
        $transcription = $this->completedTranscription(['es', 'fr']);

        $content = $this->createMock(Content::class);
        $content->method('getTranscription')->willReturn($transcription);
        $this->contentRepo->method('find')->willReturn($content);

        $this->translationService
            ->method('translate')
            ->willReturnCallback(function (array $segments, string $lang) {
                if ('es' === $lang) {
                    throw new \RuntimeException('AI call failed');
                }

                return [['start' => 0.0, 'end' => 1.0, 'text' => 'Bonjour le monde.']];
            });

        $this->em->expects($this->once())->method('flush');

        ($this->handler)(new GenerateCaptionsMessage(self::CONTENT_ID));

        $this->assertNull($transcription->getTranslation('es'));
        $this->assertNotNull($transcription->getTranslation('fr'));
    }

    public function testReturnsSilentlyWhenContentNotFound(): void
    {
        $this->contentRepo->method('find')->willReturn(null);

        $this->translationService->expects($this->never())->method('translate');
        $this->em->expects($this->never())->method('flush');

        ($this->handler)(new GenerateCaptionsMessage(self::CONTENT_ID));
    }

    public function testReturnsSilentlyWhenTranscriptionIncomplete(): void
    {
        $transcription = new VideoTranscription(self::UPLOAD_ID, self::FILENAME);

        $content = $this->createMock(Content::class);
        $content->method('getTranscription')->willReturn($transcription);
        $this->contentRepo->method('find')->willReturn($content);

        $this->translationService->expects($this->never())->method('translate');
        $this->em->expects($this->never())->method('flush');

        ($this->handler)(new GenerateCaptionsMessage(self::CONTENT_ID));
    }
}
