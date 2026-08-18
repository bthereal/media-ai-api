<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\TranscriptionException;
use League\Flysystem\FilesystemOperator;
use Symfony\AI\Platform\Bridge\OpenAi\Whisper\Result\Transcript;
use Symfony\AI\Platform\Message\Content\Audio;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\ObjectResult;
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
     * @return array{text: string, segments: list<array{start: float, end: float, text: string}>, language: string}
     *
     * @throws TranscriptionException
     */
    public function transcribe(string $uploadId, string $filename, ?float $durationSeconds = null): array
    {
        $path = "{$uploadId}/{$filename}";
        $tmpMp4 = tempnam(sys_get_temp_dir(), 'transcribe_');

        try {
            $src = $this->filesystem->readStream($path);
            $dest = fopen($tmpMp4, 'wb');
            stream_copy_to_stream($src, $dest);
            fclose($src);
            fclose($dest);
        } catch (\Throwable $e) {
            throw new TranscriptionException(
                "Failed to read file {$path}: " . $e->getMessage(),
                previous: $e,
            );
        }

        $tmpAudio = null;

        try {
            // Extract audio-only track — Whisper's 25MB limit is easily exceeded by full MP4s
            $tmpAudio = $this->audioExtractor->extractAudio($tmpMp4, $durationSeconds);

            $audio = Audio::fromFile($tmpAudio);

            $result = $this->platform->invoke('whisper-1', $audio, ['verbose' => true])->getResult();
            assert($result instanceof ObjectResult);
            $transcript = $result->getContent();
            assert($transcript instanceof Transcript);

            return [
                'text' => $transcript->getText(),
                'segments' => array_map(
                    static fn ($segment) => [
                        'start' => $segment->getStart(),
                        'end' => $segment->getEnd(),
                        'text' => $segment->getText(),
                    ],
                    $transcript->getSegments(),
                ),
                'language' => $transcript->getLanguage(),
            ];
        } catch (TranscriptionException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new TranscriptionException(
                "Transcription failed for {$uploadId}/{$filename}: " . $e->getMessage(),
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
