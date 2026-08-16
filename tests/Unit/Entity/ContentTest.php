<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Content;
use App\Entity\VideoTranscription;
use PHPUnit\Framework\TestCase;

class ContentTest extends TestCase
{
    private const FILENAME = 'video.mp4';
    private const UPLOAD_ID = '550e8400-e29b-41d4-a716-446655440000';
    private const MIME_TYPE = 'video/mp4';
    private const FILE_SIZE = 1048576;
    private const FILE_HASH = 'abc123def456abc123def456abc123def456abc123def456abc123def456abcd';

    public function testConstructorSetsAllFields(): void
    {
        $content = new Content(
            filename: self::FILENAME,
            uploadId: self::UPLOAD_ID,
            mimeType: self::MIME_TYPE,
            fileSize: self::FILE_SIZE,
            fileHash: self::FILE_HASH,
            duration: 42.5,
        );

        $this->assertSame(self::FILENAME, $content->getFilename());
        $this->assertSame(self::UPLOAD_ID, $content->getUploadId());
        $this->assertSame(self::MIME_TYPE, $content->getMimeType());
        $this->assertSame(self::FILE_SIZE, $content->getFileSize());
        $this->assertSame(self::FILE_HASH, $content->getFileHash());
        $this->assertSame(42.5, $content->getDuration());
        $this->assertNull($content->getTranscription());
        $this->assertInstanceOf(\DateTimeImmutable::class, $content->getCreatedAt());
    }

    public function testDurationIsNullableByDefault(): void
    {
        $content = new Content(
            filename: self::FILENAME,
            uploadId: self::UPLOAD_ID,
            mimeType: self::MIME_TYPE,
            fileSize: self::FILE_SIZE,
            fileHash: self::FILE_HASH,
        );

        $this->assertNull($content->getDuration());
    }

    public function testSetTranscriptionLinksRecord(): void
    {
        $content = new Content(
            filename: self::FILENAME,
            uploadId: self::UPLOAD_ID,
            mimeType: self::MIME_TYPE,
            fileSize: self::FILE_SIZE,
            fileHash: self::FILE_HASH,
        );

        $transcription = new VideoTranscription(self::UPLOAD_ID, self::FILENAME);
        $content->setTranscription($transcription);

        $this->assertSame($transcription, $content->getTranscription());
    }

    public function testCreatedAtIsSetAtConstruction(): void
    {
        $before = new \DateTimeImmutable();
        $content = new Content(
            filename: self::FILENAME,
            uploadId: self::UPLOAD_ID,
            mimeType: self::MIME_TYPE,
            fileSize: self::FILE_SIZE,
            fileHash: self::FILE_HASH,
        );
        $after = new \DateTimeImmutable();

        $this->assertGreaterThanOrEqual($before, $content->getCreatedAt());
        $this->assertLessThanOrEqual($after, $content->getCreatedAt());
    }

    public function testArchiveSetsDeletedAt(): void
    {
        $content = new Content(
            filename: self::FILENAME,
            uploadId: self::UPLOAD_ID,
            mimeType: self::MIME_TYPE,
            fileSize: self::FILE_SIZE,
            fileHash: self::FILE_HASH,
        );

        $this->assertNull($content->getDeletedAt());
        $content->archive();
        $this->assertNotNull($content->getDeletedAt());
    }

    public function testUnarchiveClearsDeletedAt(): void
    {
        $content = new Content(
            filename: self::FILENAME,
            uploadId: self::UPLOAD_ID,
            mimeType: self::MIME_TYPE,
            fileSize: self::FILE_SIZE,
            fileHash: self::FILE_HASH,
        );

        $content->archive();
        $this->assertNotNull($content->getDeletedAt());

        $content->unarchive();
        $this->assertNull($content->getDeletedAt());
    }
}
