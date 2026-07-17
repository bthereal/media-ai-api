<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\TranscriptionException;
use League\Flysystem\FilesystemOperator;
use Symfony\AI\Platform\Message\Content\Audio;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class VideoTranscriptionService
{
    public function __construct(
        #[Autowire(service: 'ai.platform.openai')]
        private readonly PlatformInterface $platform,
        private readonly FilesystemOperator $filesystem,
        private readonly AudioExtractor $audioExtractor,
    ) {
    }

    /**
     * @throws TranscriptionException
     */
    public function transcribe(string $uploadId, string $filename): string
    {
        $path = "{$uploadId}/{$filename}";

        try {
            $content = $this->filesystem->read($path);
        } catch (\Throwable $e) {
            throw new TranscriptionException(
                "Failed to read file {$path}: ".$e->getMessage(),
                previous: $e,
            );
        }

        $tmpMp4 = tempnam(sys_get_temp_dir(), 'transcribe_');
        $tmpAudio = null;

        try {
            file_put_contents($tmpMp4, $content);

            // Extract audio-only track — Whisper's 25MB limit is easily exceeded by full MP4s
            $tmpAudio = $this->audioExtractor->extractAudio($tmpMp4);

            $audio = Audio::fromFile($tmpAudio);

            return $this->platform->invoke('whisper-1', $audio)->asText();
        } catch (TranscriptionException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new TranscriptionException(
                "Transcription failed for {$uploadId}/{$filename}: ".$e->getMessage(),
                previous: $e,
            );
        } finally {
            if (file_exists($tmpMp4)) {
                unlink($tmpMp4);
            }
            if (null !== $tmpAudio && file_exists($tmpAudio)) {
                unlink($tmpAudio);
            }
        }
    }
}
