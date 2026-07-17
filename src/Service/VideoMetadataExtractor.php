<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\Process\Process;

class VideoMetadataExtractor
{
    public function extractDuration(string $filePath): ?float
    {
        $process = new Process([
            'ffprobe',
            '-v', 'quiet',
            '-print_format', 'json',
            '-show_format',
            $filePath,
        ]);

        try {
            $process->mustRun();
            $data = json_decode($process->getOutput(), true);

            return isset($data['format']['duration'])
                ? (float) $data['format']['duration']
                : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
