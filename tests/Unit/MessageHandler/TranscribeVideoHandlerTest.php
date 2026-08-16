<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\Entity\Content;
use App\Entity\VideoTranscription;
use App\Exception\TranscriptionException;
use App\Message\EmbedVideoSummaryMessage;
use App\Message\GenerateChaptersMessage;
use App\Message\GenerateTagsMessage;
use App\Message\TranscribeVideoMessage;
use App\MessageHandler\TranscribeVideoHandler;
use App\Repository\ContentRepository;
use App\Service\VideoTranscriptionService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

#[AllowMockObjectsWithoutExpectations]
class TranscribeVideoHandlerTest extends TestCase
{
    private VideoTranscriptionService&MockObject $service;
    private EntityManagerInterface&MockObject $em;
    private EntityRepository&MockObject $repo;
    private ContentRepository&MockObject $contentRepo;
    private MessageBusInterface&MockObject $bus;
    private TranscribeVideoHandler $handler;

    private const UPLOAD_ID = '550e8400-e29b-41d4-a716-446655440000';
    private const FILENAME = 'video.mp4';

    protected function setUp(): void
    {
        $this->service = $this->createMock(VideoTranscriptionService::class);
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->repo = $this->createMock(EntityRepository::class);
        $this->contentRepo = $this->createMock(ContentRepository::class);
        $this->bus = $this->createMock(MessageBusInterface::class);

        $this->em->expects($this->atLeastOnce())
            ->method('getRepository')
            ->with(VideoTranscription::class)
            ->willReturn($this->repo);

        $this->handler = new TranscribeVideoHandler(
            $this->service,
            $this->em,
            $this->contentRepo,
            $this->bus,
        );
    }

    public function testHandlerSetsStatusCompletedOnSuccess(): void
    {
        $record = new VideoTranscription(self::UPLOAD_ID, self::FILENAME);

        $this->repo->method('findOneBy')->willReturn($record);

        $this->service
            ->expects($this->once())
            ->method('transcribe')
            ->with(self::UPLOAD_ID, self::FILENAME)
            ->willReturn([
                'text' => 'The transcribed text.',
                'segments' => [['start' => 0.0, 'end' => 5.0, 'text' => 'The transcribed text.']],
                'language' => 'english',
            ]);

        $this->em->expects($this->exactly(2))->method('flush');

        $content = $this->createMock(Content::class);
        $content->method('getId')->willReturn(Uuid::fromString('660e8400-e29b-41d4-a716-446655440001'));

        $this->contentRepo->method('findOneBy')->willReturn($content);

        $dispatchedMessages = [];
        $this->bus
            ->expects($this->exactly(3))
            ->method('dispatch')
            ->willReturnCallback(function (object $message) use (&$dispatchedMessages) {
                $dispatchedMessages[] = $message;

                return new Envelope($message);
            });

        ($this->handler)(new TranscribeVideoMessage(self::UPLOAD_ID, self::FILENAME));

        $this->assertSame('completed', $record->getStatus());
        $this->assertSame('The transcribed text.', $record->getTranscription());
        $this->assertSame([['start' => 0.0, 'end' => 5.0, 'text' => 'The transcribed text.']], $record->getSegments());
        $this->assertSame('english', $record->getLanguage());
        $this->assertNotNull($record->getCompletedAt());
        $this->assertInstanceOf(EmbedVideoSummaryMessage::class, $dispatchedMessages[0]);
        $this->assertInstanceOf(GenerateChaptersMessage::class, $dispatchedMessages[1]);
        $this->assertInstanceOf(GenerateTagsMessage::class, $dispatchedMessages[2]);
    }

    public function testHandlerSetsStatusFailedAndRethrowsOnException(): void
    {
        $record = new VideoTranscription(self::UPLOAD_ID, self::FILENAME);

        $this->repo->method('findOneBy')->willReturn($record);

        $this->service
            ->method('transcribe')
            ->willThrowException(new TranscriptionException('API down'));

        $this->em->expects($this->exactly(2))->method('flush');

        $this->bus->expects($this->never())->method('dispatch');

        $this->expectException(TranscriptionException::class);

        ($this->handler)(new TranscribeVideoMessage(self::UPLOAD_ID, self::FILENAME));

        $this->assertSame('failed', $record->getStatus());
        $this->assertSame('API down', $record->getErrorMessage());
    }

    public function testHandlerReturnsSilentlyWhenEntityNotFound(): void
    {
        $this->repo->method('findOneBy')->willReturn(null);

        $this->service->expects($this->never())->method('transcribe');
        $this->em->expects($this->never())->method('flush');
        $this->bus->expects($this->never())->method('dispatch');

        ($this->handler)(new TranscribeVideoMessage(self::UPLOAD_ID, self::FILENAME));
    }
}
