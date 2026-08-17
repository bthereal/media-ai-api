<?php

declare(strict_types=1);

namespace App\Tests\Unit\Dto;

use App\Dto\ContentDto;
use App\Dto\TranscriptionDto;
use App\Entity\Content;
use App\Entity\VideoTranscription;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

class ContentDtoTest extends TestCase
{
    private const UPLOAD_ID = '550e8400-e29b-41d4-a716-446655440000';
    private const FILE_HASH = 'abc123def456abc123def456abc123def456abc123def456abc123def456abcd';

    private function makeContent(string $filename = 'video.mp4', ?float $duration = null, string $hash = self::FILE_HASH): Content
    {
        $content = new Content(
            filename: $filename,
            uploadId: self::UPLOAD_ID,
            mimeType: 'video/mp4',
            fileSize: 1048576,
            fileHash: $hash,
            duration: $duration,
        );
        // $id is Doctrine-managed; initialize via reflection for unit testing
        $prop = new \ReflectionProperty(Content::class, 'id');
        $prop->setValue($content, Uuid::fromString(self::UPLOAD_ID));

        return $content;
    }

    public function testFromEntityMapsAllFields(): void
    {
        $content = $this->makeContent(duration: 45.3);

        $dto = ContentDto::fromEntity($content);

        $this->assertTrue($dto->ok);
        $this->assertSame('video.mp4', $dto->filename);
        $this->assertSame(self::UPLOAD_ID, $dto->uploadId);
        $this->assertSame('video/mp4', $dto->mimeType);
        $this->assertSame(1048576, $dto->fileSize);
        $this->assertSame(45.3, $dto->duration);
        $this->assertNull($dto->transcription);
        $this->assertNotEmpty($dto->createdAt);
    }

    public function testFromEntityIncludesTranscriptionWhenSet(): void
    {
        $transcription = new VideoTranscription(self::UPLOAD_ID, 'video.mp4');
        $transcription->markCompleted('Hello world.');

        $content = $this->makeContent();
        $content->setTranscription($transcription);

        $dto = ContentDto::fromEntity($content);

        $this->assertInstanceOf(TranscriptionDto::class, $dto->transcription);
        $this->assertSame('completed', $dto->transcription->status);
        $this->assertSame('Hello world.', $dto->transcription->text);
        $this->assertNotNull($dto->transcription->completedAt);
    }

    public function testTranscriptionDtoFromPendingEntity(): void
    {
        $transcription = new VideoTranscription(self::UPLOAD_ID, 'video.mp4');
        $dto = TranscriptionDto::fromEntity($transcription);

        $this->assertSame('pending', $dto->status);
        $this->assertNull($dto->text);
        $this->assertNull($dto->completedAt);
        $this->assertNull($dto->chapters);
        $this->assertNull($dto->captions);
    }

    public function testTranscriptionDtoMapsCaptionsWhenLanguageKnown(): void
    {
        $transcription = new VideoTranscription(self::UPLOAD_ID, 'video.mp4');
        $transcription->markCompleted('Hello world.', [['start' => 0.0, 'end' => 5.0, 'text' => 'Hello world.']], 'english');

        $dto = TranscriptionDto::fromEntity($transcription);

        $this->assertNotNull($dto->captions);
        $this->assertSame('en', $dto->captions->nativeLanguage->code);
        $this->assertSame('English', $dto->captions->nativeLanguage->label);
        $this->assertNotEmpty($dto->captions->availableTranslations);
        // The native language must never also be offered as a translation target
        foreach ($dto->captions->availableTranslations as $option) {
            $this->assertNotSame('en', $option->code);
        }
    }

    public function testTranscriptionDtoMapsChaptersWhenGenerated(): void
    {
        $transcription = new VideoTranscription(self::UPLOAD_ID, 'video.mp4');
        $transcription->markCompleted('Hello world.', [['start' => 0.0, 'end' => 10.0, 'text' => 'Hello world.']]);
        $transcription->setChapters([['title' => 'Intro', 'startSeconds' => 0.0, 'endSeconds' => 10.0]]);

        $dto = TranscriptionDto::fromEntity($transcription);

        $this->assertNotNull($dto->chapters);
        $this->assertCount(1, $dto->chapters);
        $this->assertSame('Intro', $dto->chapters[0]->title);
        $this->assertSame(0.0, $dto->chapters[0]->startSeconds);
        $this->assertSame(10.0, $dto->chapters[0]->endSeconds);
    }

    public function testTranscriptionDtoMapsTagsAndCategoryWhenGenerated(): void
    {
        $transcription = new VideoTranscription(self::UPLOAD_ID, 'video.mp4');
        $transcription->markCompleted('Hello world.');
        $transcription->setTagsAndCategory(['ai', 'video'], 'Tutorial');

        $dto = TranscriptionDto::fromEntity($transcription);

        $this->assertSame(['ai', 'video'], $dto->tags);
        $this->assertSame('Tutorial', $dto->category);
    }

    public function testTranscriptionDtoTagsAndCategoryAreNullBeforeTagged(): void
    {
        $transcription = new VideoTranscription(self::UPLOAD_ID, 'video.mp4');
        $transcription->markCompleted('Hello world.');

        $dto = TranscriptionDto::fromEntity($transcription);

        $this->assertNull($dto->tags);
        $this->assertNull($dto->category);
    }
}
