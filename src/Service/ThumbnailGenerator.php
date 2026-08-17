<?php

declare(strict_types=1);

namespace App\Service;

use League\Flysystem\FilesystemOperator;
use Symfony\Component\Process\Process;

class ThumbnailGenerator
{
    private const THUMBNAIL_PATH = 'thumbnail.jpg';
    private const WIDTH = 640;

    /** @var list<float> */
    private const CANDIDATE_PERCENTAGES = [0.10, 0.30, 0.50, 0.70];

    public function __construct(
        private readonly FilesystemOperator $filesystem,
        private readonly ThumbnailPickerService $pickerService,
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
        try {
            $videoContent = $this->filesystem->read("{$uploadId}/{$filename}");
        } catch (\Throwable) {
            return 0;
        }

        $tmpVideo = tempnam(sys_get_temp_dir(), 'thumb_v_') . '.mp4';
        file_put_contents($tmpVideo, $videoContent);

        $candidates = [];

        try {
            foreach (self::candidateSeekTimes($duration) as $seekSeconds) {
                $tmpJpeg = tempnam(sys_get_temp_dir(), 'thumb_j_') . '.jpg';
                if ($this->runFfmpeg($tmpVideo, $tmpJpeg, $seekSeconds)) {
                    $candidates[] = $tmpJpeg;
                } else {
                    @unlink($tmpJpeg);
                }
            }

            if ([] === $candidates) {
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
        } catch (\Throwable) {
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

    private function runFfmpeg(string $input, string $output, float $seekSeconds): bool
    {
        $args = [
            'ffmpeg',
            '-i', $input,
            '-ss', (string) $seekSeconds,
            '-vframes', '1',
            '-vf', 'scale=' . self::WIDTH . ':-1',
            '-q:v', '3',
            '-y',
            $output,
        ];

        $process = new Process($args);
        $process->run();

        return $process->isSuccessful() && file_exists($output) && filesize($output) > 0;
    }
}
