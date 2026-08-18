<?php

declare(strict_types=1);

namespace App\Service;

use League\Flysystem\FilesystemOperator;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;

class ThumbnailGenerator
{
    private const THUMBNAIL_PATH = 'thumbnail.jpg';
    private const WIDTH = 640;

    /**
     * Per-frame ffmpeg extraction budget. Generous since a correctly-seeking ffmpeg
     * call (see runFfmpeg()) only needs to decode a handful of frames around the
     * seek point, not the whole video up to it — this is a safety net, not the
     * expected duration.
     */
    private const int FFMPEG_TIMEOUT_SECONDS = 30;

    /** @var list<float> */
    private const CANDIDATE_PERCENTAGES = [0.10, 0.30, 0.50, 0.70];

    public function __construct(
        private readonly FilesystemOperator $filesystem,
        private readonly ThumbnailPickerService $pickerService,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Reads the video from Flysystem, extracts several candidate frames, persists
     * ALL of them to Flysystem (so a user can later pick a different one), uses a
     * vision model to auto-pick the most visually representative one (falling back
     * to the earliest candidate if the AI call fails) as the default thumbnail.jpg.
     * Returns the number of candidates persisted (0 means generation failed entirely).
     */
    public function generate(string $uploadId, string $filename, ?float $duration = null): int
    {
        $tmpVideo = tempnam(sys_get_temp_dir(), 'thumb_v_') . '.mp4';

        try {
            $src = $this->filesystem->readStream("{$uploadId}/{$filename}");
            $dest = fopen($tmpVideo, 'wb');
            stream_copy_to_stream($src, $dest);
            fclose($src);
            fclose($dest);
        } catch (\Throwable $e) {
            $this->logger->warning('ThumbnailGenerator: failed to read source video', ['uploadId' => $uploadId, 'error' => $e->getMessage()]);

            return 0;
        }

        $candidates = [];

        try {
            foreach (self::candidateSeekTimes($duration) as $seekSeconds) {
                $tmpJpeg = tempnam(sys_get_temp_dir(), 'thumb_j_') . '.jpg';
                if ($this->runFfmpeg($uploadId, $tmpVideo, $tmpJpeg, $seekSeconds)) {
                    $candidates[] = $tmpJpeg;
                } else {
                    @unlink($tmpJpeg);
                }
            }

            if ([] === $candidates) {
                $this->logger->warning('ThumbnailGenerator: no candidate frames could be extracted', ['uploadId' => $uploadId]);

                return 0;
            }

            foreach ($candidates as $index => $candidate) {
                $this->filesystem->write(
                    self::candidatePath($uploadId, $index),
                    file_get_contents($candidate),
                );
            }

            $bestPath = $this->pickBest($candidates);

            $this->filesystem->write(
                "{$uploadId}/" . self::THUMBNAIL_PATH,
                file_get_contents($bestPath),
            );

            return count($candidates);
        } catch (\Throwable $e) {
            $this->logger->warning('ThumbnailGenerator: generation failed', ['uploadId' => $uploadId, 'error' => $e->getMessage()]);

            return 0;
        } finally {
            unlink($tmpVideo);
            foreach ($candidates as $candidate) {
                if (file_exists($candidate)) {
                    unlink($candidate);
                }
            }
        }
    }

    public static function thumbnailPath(string $uploadId): string
    {
        return "{$uploadId}/" . self::THUMBNAIL_PATH;
    }

    public static function candidatePath(string $uploadId, int $index): string
    {
        return "{$uploadId}/thumb-candidate-{$index}.jpg";
    }

    /**
     * Seek offsets (in seconds) at which to extract candidate frames. Percentage-based
     * when the duration is known; falls back to the original fixed 1s/0s attempts
     * (deduped) for very short or duration-unknown videos.
     *
     * @return list<float>
     */
    public static function candidateSeekTimes(?float $duration): array
    {
        if (null === $duration || $duration <= 0) {
            return [1.0, 0.0];
        }

        $times = array_map(static fn (float $p): float => round($duration * $p, 2), self::CANDIDATE_PERCENTAGES);

        return array_values(array_unique($times));
    }

    /**
     * @param list<string> $candidates absolute paths to candidate JPEGs
     */
    private function pickBest(array $candidates): string
    {
        if (1 === count($candidates)) {
            return $candidates[0];
        }

        try {
            $index = $this->pickerService->pickBest($candidates);

            return $candidates[$index];
        } catch (\Throwable) {
            // Vision call failed or returned something unparseable — degrade to the
            // earliest candidate rather than losing the thumbnail entirely.
            return $candidates[0];
        }
    }

    private function runFfmpeg(string $uploadId, string $input, string $output, float $seekSeconds): bool
    {
        $args = [
            'ffmpeg',
            // -ss before -i uses fast keyframe-based seeking (ffmpeg only decodes a
            // few frames around the seek point). Placing it after -i instead forces
            // slow full-decode seeking from the start of the file — harmless for a
            // short demo clip, but for a long/high-resolution video it can take
            // minutes per frame and blow the process timeout below, silently
            // producing zero candidates.
            '-ss', (string) $seekSeconds,
            '-i', $input,
            '-vframes', '1',
            '-vf', 'scale=' . self::WIDTH . ':-1',
            '-q:v', '3',
            '-y',
            $output,
        ];

        $process = new Process($args);
        $process->setTimeout(self::FFMPEG_TIMEOUT_SECONDS);

        try {
            $process->run();
        } catch (\Throwable $e) {
            $this->logger->warning('ThumbnailGenerator: ffmpeg extraction failed', [
                'uploadId' => $uploadId,
                'seekSeconds' => $seekSeconds,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        if (!$process->isSuccessful()) {
            $this->logger->warning('ThumbnailGenerator: ffmpeg exited unsuccessfully', [
                'uploadId' => $uploadId,
                'seekSeconds' => $seekSeconds,
                'exitCode' => $process->getExitCode(),
                'stderr' => $process->getErrorOutput(),
            ]);

            return false;
        }

        return file_exists($output) && filesize($output) > 0;
    }
}
