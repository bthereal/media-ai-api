<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Exception\TranscriptionException;
use App\Service\AudioExtractor;
use App\Service\VideoTranscriptionService;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\UnableToReadFile;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\OpenAi\Whisper\Result\Segment;
use Symfony\AI\Platform\Bridge\OpenAi\Whisper\Result\Transcript;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\AI\Platform\Result\InMemoryRawResult;
use Symfony\AI\Platform\Result\ObjectResult;
use Symfony\AI\Platform\ResultConverterInterface;

#[AllowMockObjectsWithoutExpectations]
class VideoTranscriptionServiceTest extends TestCase
{
    private PlatformInterface&MockObject $platform;
    private FilesystemOperator&MockObject $filesystem;
    private AudioExtractor&MockObject $audioExtractor;
    private VideoTranscriptionService $service;

    private const UPLOAD_ID = '550e8400-e29b-41d4-a716-446655440000';
    private const FILENAME = 'video.mp4';

    protected function setUp(): void
    {
        $this->platform = $this->createMock(PlatformInterface::class);
        $this->filesystem = $this->createMock(FilesystemOperator::class);
        $this->audioExtractor = $this->createMock(AudioExtractor::class);

        $this->service = new VideoTranscriptionService(
            $this->platform,
            $this->filesystem,
            $this->audioExtractor,
        );
    }

    private function makeDeferredResult(Transcript $transcript): DeferredResult
    {
        $converter = $this->createMock(ResultConverterInterface::class);
        $converter->method('convert')->willReturn(new ObjectResult($transcript));
        $converter->method('getTokenUsageExtractor')->willReturn(null);

        return new DeferredResult($converter, new InMemoryRawResult());
    }

    public function testTranscribeReturnsTextOnSuccess(): void
    {
        $tmpAudio = tempnam(sys_get_temp_dir(), 'test_audio_');

        $this->filesystem
            ->expects($this->once())
            ->method('read')
            ->with(self::UPLOAD_ID.'/'.self::FILENAME)
            ->willReturn('fake-mp4-binary-content');

        $this->audioExtractor
            ->expects($this->once())
            ->method('extractAudio')
            ->willReturn($tmpAudio);

        $transcript = new Transcript(
            'Hello world transcription.',
            'en',
            12.5,
            [new Segment(0.0, 6.0, 'Hello world'), new Segment(6.0, 12.5, 'transcription.')],
        );

        $this->platform
            ->expects($this->once())
            ->method('invoke')
            ->with('whisper-1', $this->anything(), ['verbose' => true])
            ->willReturn($this->makeDeferredResult($transcript));

        $result = $this->service->transcribe(self::UPLOAD_ID, self::FILENAME);

        $this->assertSame('Hello world transcription.', $result['text']);
        $this->assertSame(
            [
                ['start' => 0.0, 'end' => 6.0, 'text' => 'Hello world'],
                ['start' => 6.0, 'end' => 12.5, 'text' => 'transcription.'],
            ],
            $result['segments'],
        );
        $this->assertSame('en', $result['language']);
    }

    public function testTranscribeThrowsOnFilesystemReadFailure(): void
    {
        $this->filesystem
            ->method('read')
            ->willThrowException(UnableToReadFile::fromLocation(self::UPLOAD_ID.'/'.self::FILENAME));

        $this->expectException(TranscriptionException::class);
        $this->expectExceptionMessage('Failed to read file');

        $this->service->transcribe(self::UPLOAD_ID, self::FILENAME);
    }

    public function testTranscribeThrowsOnAudioExtractionFailure(): void
    {
        $this->filesystem->method('read')->willReturn('fake-content');

        $this->audioExtractor
            ->method('extractAudio')
            ->willThrowException(new TranscriptionException('Audio extraction failed: ffmpeg error'));

        $this->expectException(TranscriptionException::class);
        $this->expectExceptionMessage('Audio extraction failed');

        $this->service->transcribe(self::UPLOAD_ID, self::FILENAME);
    }

    public function testTranscribeThrowsOnPlatformFailure(): void
    {
        $tmpAudio = tempnam(sys_get_temp_dir(), 'test_audio_');

        $this->filesystem->method('read')->willReturn('fake-content');
        $this->audioExtractor->method('extractAudio')->willReturn($tmpAudio);

        $this->platform
            ->method('invoke')
            ->willThrowException(new \RuntimeException('OpenAI API error'));

        $this->expectException(TranscriptionException::class);
        $this->expectExceptionMessage('Transcription failed');

        $this->service->transcribe(self::UPLOAD_ID, self::FILENAME);
    }
}
