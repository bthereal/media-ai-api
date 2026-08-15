<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Formats Whisper-shaped segments into a standard WebVTT file — pure, no I/O or AI calls.
 */
final class VttFormatter
{
    /**
     * @param list<array{start: float, end: float, text: string}> $segments
     */
    public static function format(array $segments): string
    {
        $lines = ['WEBVTT', ''];

        foreach ($segments as $index => $segment) {
            $text = trim($segment['text']);
            if ('' === $text) {
                continue;
            }

            $lines[] = (string) ($index + 1);
            $lines[] = sprintf(
                '%s --> %s',
                self::formatTimestamp($segment['start']),
                self::formatTimestamp($segment['end']),
            );
            $lines[] = $text;
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    private static function formatTimestamp(float $seconds): string
    {
        // Round to a single integer millisecond count first, then decompose via
        // integer div/mod — avoids float modulo (deprecated) and cascades rollover
        // (e.g. 59.9996s -> 1:00.000, not 0:59.9996 truncated to 60 "seconds").
        $totalMillis = (int) round(max(0.0, $seconds) * 1000);

        $hours = intdiv($totalMillis, 3_600_000);
        $totalMillis %= 3_600_000;
        $minutes = intdiv($totalMillis, 60_000);
        $totalMillis %= 60_000;
        $secs = intdiv($totalMillis, 1000);
        $millis = $totalMillis % 1000;

        return sprintf('%02d:%02d:%02d.%03d', $hours, $minutes, $secs, $millis);
    }
}
