<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\TranscriptionException;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class AudioExtractor
{
    /**
     * Extracts the audio track from an MP4 file into a temporary MP3 file.
     * Returns the path to the temporary MP3 — caller is responsible for deleting it.
     *
     * @throws TranscriptionException if ffmpeg is not available or extraction fails
     */
    public function extractAudio(string $mp4Path): string
    {
        $mp3Path = tempnam(sys_get_temp_dir(), 'audio_').'.mp3';

        $process = new Process([
            'ffmpeg',
            '-i', $mp4Path,
            '-vn',               // strip video track
            '-acodec', 'libmp3lame',
            '-q:a', '4',         // ~128kbps — sufficient for speech recognition
            '-y',                // overwrite output if exists
            $mp3Path,
        ]);

        try {
            $process->mustRun();
        } catch (ProcessFailedException $e) {
            if (file_exists($mp3Path)) {
                unlink($mp3Path);
            }

            throw new TranscriptionException(
                'Audio extraction failed: '.$process->getErrorOutput(),
                previous: $e,
            );
        }

        return $mp3Path;
    }
}
