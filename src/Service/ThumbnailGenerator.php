<?php

declare(strict_types=1);

namespace App\Service;

use League\Flysystem\FilesystemOperator;
use Symfony\Component\Process\Process;

class ThumbnailGenerator
{
    private const THUMBNAIL_PATH = 'thumbnail.jpg';
    private const WIDTH = 640;

    public function __construct(private readonly FilesystemOperator $filesystem) {}

    /**
     * Reads the video from Flysystem, runs ffmpeg to extract a frame,
     * and writes thumbnail.jpg back to Flysystem under the same uploadId prefix.
     * Returns true on success, false on any failure (non-fatal).
     */
    public function generate(string $uploadId, string $filename): bool
    {
        try {
            $videoContent = $this->filesystem->read("{$uploadId}/{$filename}");
        } catch (\Throwable) {
            return false;
        }

        $tmpVideo = tempnam(sys_get_temp_dir(), 'thumb_v_').'.mp4';
        $tmpJpeg = tempnam(sys_get_temp_dir(), 'thumb_j_').'.jpg';

        try {
            file_put_contents($tmpVideo, $videoContent);

            if (!$this->runFfmpeg($tmpVideo, $tmpJpeg, seekSeconds: 1)) {
                // Retry at the very first frame for videos shorter than 1 s
                $this->runFfmpeg($tmpVideo, $tmpJpeg, seekSeconds: 0);
            }

            if (!file_exists($tmpJpeg) || filesize($tmpJpeg) === 0) {
                return false;
            }

            $this->filesystem->write(
                "{$uploadId}/".self::THUMBNAIL_PATH,
                file_get_contents($tmpJpeg),
            );

            return true;
        } catch (\Throwable) {
            return false;
        } finally {
            foreach ([$tmpVideo, $tmpJpeg] as $f) {
                if (file_exists($f)) {
                    unlink($f);
                }
            }
        }
    }

    public static function thumbnailPath(string $uploadId): string
    {
        return "{$uploadId}/".self::THUMBNAIL_PATH;
    }

    private function runFfmpeg(string $input, string $output, int $seekSeconds): bool
    {
        $args = [
            'ffmpeg',
            '-i', $input,
            '-ss', (string) $seekSeconds,
            '-vframes', '1',
            '-vf', 'scale='.self::WIDTH.':-1',
            '-q:v', '3',
            '-y',
            $output,
        ];

        $process = new Process($args);
        $process->run();

        return $process->isSuccessful() && file_exists($output) && filesize($output) > 0;
    }
}
