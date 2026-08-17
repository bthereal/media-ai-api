<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Exception\MissingChunksException;
use App\Exception\StorageException;
use App\Exception\ValidationException;
use App\Service\ChunkUploadService;
use League\Flysystem\FilesystemOperator;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

#[AllowMockObjectsWithoutExpectations]
class ChunkUploadServiceTest extends TestCase
{
    private FilesystemOperator&MockObject $filesystem;
    private ChunkUploadService $service;
    private string $tmpFile;

    protected function setUp(): void
    {
        $this->filesystem = $this->createMock(FilesystemOperator::class);
        $this->service = new ChunkUploadService($this->filesystem);

        $this->tmpFile = tempnam(sys_get_temp_dir(), 'chunk_test_');
        file_put_contents($this->tmpFile, str_repeat('x', 1024));
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tmpFile)) {
            unlink($this->tmpFile);
        }
    }

    private function makeChunk(int $sizeOverride = 0): UploadedFile
    {
        if ($sizeOverride > 0) {
            file_put_contents($this->tmpFile, str_repeat('x', $sizeOverride));
        }

        return new UploadedFile($this->tmpFile, 'chunk.mp4', 'video/mp4', null, true);
    }

    // --- validateChunk ---

    public function testValidateChunkPassesWithValidInput(): void
    {
        $this->service->validateChunk('550e8400-e29b-41d4-a716-446655440000', 0, 1, 'video.mp4', $this->makeChunk());
        $this->addToAssertionCount(1);
    }

    public function testValidateChunkThrowsOnInvalidUuid(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid uploadId');

        $this->service->validateChunk('not-a-uuid', 0, 1, 'video.mp4', $this->makeChunk());
    }

    public function testValidateChunkThrowsOnInvalidUuidHttpStatus(): void
    {
        try {
            $this->service->validateChunk('not-a-uuid', 0, 1, 'video.mp4', $this->makeChunk());
        } catch (ValidationException $e) {
            $this->assertSame(400, $e->getHttpStatus());

            return;
        }
        $this->fail('Expected ValidationException');
    }

    public function testValidateChunkThrowsOnZeroTotalChunks(): void
    {
        $e = null;
        try {
            $this->service->validateChunk('550e8400-e29b-41d4-a716-446655440000', 0, 0, 'video.mp4', $this->makeChunk());
        } catch (ValidationException $caught) {
            $e = $caught;
        }
        $this->assertNotNull($e);
        $this->assertSame(400, $e->getHttpStatus());
    }

    public function testValidateChunkThrowsOnChunkIndexOutOfRange(): void
    {
        $e = null;
        try {
            $this->service->validateChunk('550e8400-e29b-41d4-a716-446655440000', 5, 3, 'video.mp4', $this->makeChunk());
        } catch (ValidationException $caught) {
            $e = $caught;
        }
        $this->assertNotNull($e);
        $this->assertSame(400, $e->getHttpStatus());
    }

    public function testValidateChunkThrowsOnNegativeChunkIndex(): void
    {
        $e = null;
        try {
            $this->service->validateChunk('550e8400-e29b-41d4-a716-446655440000', -1, 3, 'video.mp4', $this->makeChunk());
        } catch (ValidationException $caught) {
            $e = $caught;
        }
        $this->assertNotNull($e);
        $this->assertSame(400, $e->getHttpStatus());
    }

    public function testValidateChunkThrowsOnNonMp4Filename(): void
    {
        $e = null;
        try {
            $this->service->validateChunk('550e8400-e29b-41d4-a716-446655440000', 0, 1, 'video.avi', $this->makeChunk());
        } catch (ValidationException $caught) {
            $e = $caught;
        }
        $this->assertNotNull($e);
        $this->assertSame(400, $e->getHttpStatus());
    }

    public function testValidateChunkThrowsWhenChunkTooLarge(): void
    {
        $e = null;
        try {
            $this->service->validateChunk(
                '550e8400-e29b-41d4-a716-446655440000',
                0,
                1,
                'video.mp4',
                $this->makeChunk(11 * 1024 * 1024),
            );
        } catch (ValidationException $caught) {
            $e = $caught;
        }
        $this->assertNotNull($e);
        $this->assertSame(422, $e->getHttpStatus());
    }

    // --- storeChunk ---

    public function testStoreChunkWritesStreamToCorrectPath(): void
    {
        $uploadId = '550e8400-e29b-41d4-a716-446655440000';
        $chunkIndex = 2;

        $this->filesystem
            ->expects($this->once())
            ->method('writeStream')
            ->with("temp/{$uploadId}/{$chunkIndex}", $this->isResource());

        $this->service->storeChunk($uploadId, $chunkIndex, $this->makeChunk());
    }

    public function testStoreChunkThrowsStorageExceptionOnFilesystemFailure(): void
    {
        $this->filesystem
            ->method('writeStream')
            ->willThrowException(new \League\Flysystem\UnableToWriteFile());

        $this->expectException(StorageException::class);
        $this->service->storeChunk('550e8400-e29b-41d4-a716-446655440000', 0, $this->makeChunk());
    }

    // --- assembleIfComplete ---

    public function testAssembleIfCompleteReturnsFalseForNonFinalChunk(): void
    {
        $result = $this->service->assembleIfComplete('550e8400-e29b-41d4-a716-446655440000', 1, 5, 'video.mp4');
        $this->assertFalse($result);
    }

    public function testAssembleIfCompleteAssemblesAndCleansUp(): void
    {
        $uploadId = '550e8400-e29b-41d4-a716-446655440000';

        $this->filesystem->method('fileExists')->willReturn(true);
        // assembleIfComplete() reads/writes via streams (readStream/writeStream), not
        // the plain string read()/write() — each chunk needs its own fresh resource
        // since the service fclose()s the source stream after copying each one.
        $this->filesystem->method('readStream')->willReturnCallback(static function (): mixed {
            $stream = fopen('php://memory', 'r+');
            fwrite($stream, 'chunk-data');
            rewind($stream);

            return $stream;
        });
        $this->filesystem->expects($this->once())->method('writeStream')
            ->with(
                "{$uploadId}/video.mp4",
                $this->callback(static function (mixed $stream): bool {
                    return is_resource($stream) && 'chunk-datachunk-data' === stream_get_contents($stream);
                }),
            );
        $this->filesystem->expects($this->once())->method('deleteDirectory')
            ->with("temp/{$uploadId}");

        $result = $this->service->assembleIfComplete($uploadId, 1, 2, 'video.mp4');
        $this->assertTrue($result);
    }

    public function testAssembleIfCompleteThrowsMissingChunksException(): void
    {
        $this->filesystem->method('fileExists')->willReturnOnConsecutiveCalls(true, false);

        $this->expectException(MissingChunksException::class);
        $this->service->assembleIfComplete('550e8400-e29b-41d4-a716-446655440000', 2, 3, 'video.mp4');
    }
}
