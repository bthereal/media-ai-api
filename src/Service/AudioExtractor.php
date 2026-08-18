<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\TranscriptionException;
use Symfony\Component\Process\Process;

class AudioExtractor
{
    /** Whisper's hard per-file limit. */
    private const int WHISPER_MAX_BYTES = 25 * 1024 * 1024;

    /**
     * Encode against 90% of the real cap — MP3 encoders don't hit their target
     * bitrate exactly, and this leaves headroom for container/frame overhead.
     */
    private const float SAFETY_MARGIN = 0.9;

    /** Upper bound — no real transcription-accuracy benefit above this for speech. */
    private const int MAX_BITRATE_BPS = 128_000;

    /** Lower bound — below this, speech intelligibility (and so accuracy) degrades badly. */
    private const int MIN_BITRATE_BPS = 32_000;

    /** Used when duration is unknown and a size guarantee isn't possible. */
    private const int FALLBACK_BITRATE_BPS = 64_000;

    /**
     * Process's own default timeout (60s) is fine for a short clip but not for a
     * full-length audio transcode of a long video — encoding is normally many
     * times faster than realtime, but this floor+multiplier stays generous under
     * slow/loaded hardware rather than tuning against a single benchmark.
     */
    private const float MIN_TIMEOUT_SECONDS = 120.0;
    private const float TIMEOUT_PER_SOURCE_SECOND = 0.5;
    private const float FALLBACK_TIMEOUT_SECONDS = 300.0;

    /**
     * Extracts the audio track from an MP4 file into a temporary MP3 file.
     * Returns the path to the temporary MP3 — caller is responsible for deleting it.
     *
     * Encodes at a constant bitrate sized to the video's duration so the output
     * stays under Whisper's 25MB hard limit regardless of length — a fixed
     * quality setting (the previous approach) comfortably fits short videos but
     * silently exceeds the limit for anything past roughly 50 minutes, which
     * Whisper then rejects outright with a 413.
     *
     * @throws TranscriptionException if ffmpeg is not available or extraction fails
     */
    public function extractAudio(string $mp4Path, ?float $durationSeconds = null): string
    {
        $mp3Path = tempnam(sys_get_temp_dir(), 'audio_') . '.mp3';
        $bitrateBps = self::targetBitrate($durationSeconds);

        $process = new Process([
            'ffmpeg',
            '-i', $mp4Path,
            '-vn',               // strip video track
            '-acodec', 'libmp3lame',
            '-b:a', sprintf('%dk', intdiv($bitrateBps, 1000)),
            '-y',                // overwrite output if exists
            $mp3Path,
        ]);
        $process->setTimeout(self::timeout($durationSeconds));

        try {
            $process->mustRun();
        } catch (\Throwable $e) {
            if (file_exists($mp3Path)) {
                unlink($mp3Path);
            }

            throw new TranscriptionException(
                'Audio extraction failed: ' . $e->getMessage(),
                previous: $e,
            );
        }

        return $mp3Path;
    }

    public static function timeout(?float $durationSeconds): float
    {
        if (null === $durationSeconds || $durationSeconds <= 0.0) {
            return self::FALLBACK_TIMEOUT_SECONDS;
        }

        return max(self::MIN_TIMEOUT_SECONDS, $durationSeconds * self::TIMEOUT_PER_SOURCE_SECOND);
    }

    public static function targetBitrate(?float $durationSeconds): int
    {
        if (null === $durationSeconds || $durationSeconds <= 0.0) {
            return self::FALLBACK_BITRATE_BPS;
        }

        $safeBytes = (float) self::WHISPER_MAX_BYTES * self::SAFETY_MARGIN;
        $bitrate = (int) (($safeBytes * 8.0) / $durationSeconds);

        return max(self::MIN_BITRATE_BPS, min(self::MAX_BITRATE_BPS, $bitrate));
    }
}
