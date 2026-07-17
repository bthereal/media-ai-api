<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\MissingChunksException;
use App\Exception\StorageException;
use App\Exception\ValidationException;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

class ChunkUploadService
{
    private const int MAX_CHUNK_BYTES = 10 * 1024 * 1024;

    public function __construct(private readonly FilesystemOperator $filesystem)
    {
    }

    /**
     * @throws ValidationException
     */
    public function validateChunk(
        string $uploadId,
        int $chunkIndex,
        int $totalChunks,
        string $filename,
        UploadedFile $chunk,
    ): void {
        if (!Uuid::isValid($uploadId)) {
            throw new ValidationException('Invalid uploadId — must be a valid UUID.', Response::HTTP_BAD_REQUEST);
        }

        if ($totalChunks < 1) {
            throw new ValidationException('totalChunks must be at least 1.', Response::HTTP_BAD_REQUEST);
        }

        if ($chunkIndex < 0 || $chunkIndex >= $totalChunks) {
            throw new ValidationException('chunkIndex out of range.', Response::HTTP_BAD_REQUEST);
        }

        if (!str_ends_with(strtolower($filename), '.mp4') || $filename === '') {
            throw new ValidationException('Only .mp4 files are supported.', Response::HTTP_BAD_REQUEST);
        }

        if (!$chunk->isValid()) {
            throw new ValidationException('Chunk upload error: '.$chunk->getErrorMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($chunk->getSize() > self::MAX_CHUNK_BYTES) {
            throw new ValidationException(
                sprintf('Chunk too large — maximum is %d bytes.', self::MAX_CHUNK_BYTES),
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }
    }

    /**
     * @throws StorageException
     */
    public function storeChunk(string $uploadId, int $chunkIndex, UploadedFile $chunk): void
    {
        $stream = fopen($chunk->getRealPath(), 'rb');

        try {
            $this->filesystem->writeStream("temp/{$uploadId}/{$chunkIndex}", $stream);
        } catch (FilesystemException $e) {
            throw new StorageException('Failed to store chunk: '.$e->getMessage(), previous: $e);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * Returns true when all chunks have been assembled into the final file, false otherwise.
     *
     * @throws MissingChunksException
     * @throws StorageException
     */
    public function assembleIfComplete(
        string $uploadId,
        int $chunkIndex,
        int $totalChunks,
        string $filename,
    ): bool {
        if ($chunkIndex + 1 < $totalChunks) {
            return false;
        }

        try {
            for ($i = 0; $i < $totalChunks; ++$i) {
                if (!$this->filesystem->fileExists("temp/{$uploadId}/{$i}")) {
                    throw new MissingChunksException("Missing chunk {$i} for upload {$uploadId}.");
                }
            }

            $assembled = '';
            for ($i = 0; $i < $totalChunks; ++$i) {
                $assembled .= $this->filesystem->read("temp/{$uploadId}/{$i}");
            }

            $this->filesystem->write("{$uploadId}/{$filename}", $assembled);
            $this->filesystem->deleteDirectory("temp/{$uploadId}");
        } catch (MissingChunksException $e) {
            throw $e;
        } catch (FilesystemException $e) {
            throw new StorageException('Failed to assemble upload: '.$e->getMessage(), previous: $e);
        }

        return true;
    }
}
