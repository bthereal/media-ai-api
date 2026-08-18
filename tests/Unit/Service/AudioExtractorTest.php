<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\AudioExtractor;
use PHPUnit\Framework\TestCase;

class AudioExtractorTest extends TestCase
{
    private const int WHISPER_MAX_BYTES = 25 * 1024 * 1024;

    public function testTargetBitrateUsesFallbackWhenDurationUnknown(): void
    {
        $this->assertSame(64_000, AudioExtractor::targetBitrate(null));
    }

    public function testTargetBitrateUsesFallbackWhenDurationZeroOrNegative(): void
    {
        $this->assertSame(64_000, AudioExtractor::targetBitrate(0.0));
        $this->assertSame(64_000, AudioExtractor::targetBitrate(-5.0));
    }

    public function testTargetBitrateCapsAtMaximumForShortVideos(): void
    {
        // A 1-minute video could easily support a bitrate far above the quality
        // ceiling — must clamp, not just divide.
        $this->assertSame(128_000, AudioExtractor::targetBitrate(60.0));
    }

    public function testTargetBitrateFloorsAtMinimumForVeryLongVideos(): void
    {
        // Several hours — even the lowest usable bitrate would overflow 25MB, but
        // it must never drop below the intelligibility floor.
        $this->assertSame(32_000, AudioExtractor::targetBitrate(36_000.0));
    }

    public function testTargetBitrateScalesToFitDurationWithinTheHardLimit(): void
    {
        // ~52 minutes — the exact class of video that previously overflowed
        // Whisper's 25MB cap at a fixed ~128kbps-ish setting.
        $durationSeconds = 3140.949;

        $bitrate = AudioExtractor::targetBitrate($durationSeconds);
        $estimatedBytes = ($bitrate / 8) * $durationSeconds;

        $this->assertLessThan(self::WHISPER_MAX_BYTES, $estimatedBytes);
        $this->assertGreaterThanOrEqual(32_000, $bitrate);
        $this->assertLessThanOrEqual(128_000, $bitrate);
    }

    public function testTimeoutUsesFallbackWhenDurationUnknown(): void
    {
        $this->assertSame(300.0, AudioExtractor::timeout(null));
        $this->assertSame(300.0, AudioExtractor::timeout(0.0));
        $this->assertSame(300.0, AudioExtractor::timeout(-5.0));
    }

    public function testTimeoutFloorsAtMinimumForShortVideos(): void
    {
        // Process's own 60s default would be plenty here, but the floor exists so
        // ffmpeg startup overhead never eats the whole budget on tiny inputs.
        $this->assertSame(120.0, AudioExtractor::timeout(10.0));
    }

    public function testTimeoutScalesWithDurationForLongVideos(): void
    {
        // ~52 minutes — this is exactly the video that hit Process's default 60s
        // timeout once the source was long enough for the transcode itself to
        // take a while.
        $this->assertSame(1570.4745, AudioExtractor::timeout(3140.949));
    }
}
