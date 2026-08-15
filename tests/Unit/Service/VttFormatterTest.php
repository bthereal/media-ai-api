<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\VttFormatter;
use PHPUnit\Framework\TestCase;

class VttFormatterTest extends TestCase
{
    public function testFormatProducesValidWebVtt(): void
    {
        $segments = [
            ['start' => 0.0, 'end' => 5.0, 'text' => 'Hello world.'],
            ['start' => 5.0, 'end' => 10.5, 'text' => 'Second line.'],
        ];

        $vtt = VttFormatter::format($segments);

        $this->assertStringStartsWith("WEBVTT\n\n", $vtt);
        $this->assertStringContainsString("1\n00:00:00.000 --> 00:00:05.000\nHello world.", $vtt);
        $this->assertStringContainsString("2\n00:00:05.000 --> 00:00:10.500\nSecond line.", $vtt);
    }

    public function testFormatHandlesHourBoundary(): void
    {
        $segments = [['start' => 3661.999, 'end' => 3662.0, 'text' => 'Late segment.']];

        $vtt = VttFormatter::format($segments);

        $this->assertStringContainsString('01:01:01.999 --> 01:01:02.000', $vtt);
    }

    public function testFormatCascadesMillisecondRolloverIntoMinutes(): void
    {
        // 59.9996s rounds to 60000ms, which must carry into the next minute — not stay as "0:59.9996" truncated to 60s
        $segments = [['start' => 59.9996, 'end' => 60.0, 'text' => 'Boundary.']];

        $vtt = VttFormatter::format($segments);

        $this->assertStringContainsString('00:01:00.000 --> 00:01:00.000', $vtt);
    }

    public function testFormatSkipsSegmentsWithEmptyText(): void
    {
        $segments = [
            ['start' => 0.0, 'end' => 1.0, 'text' => '   '],
            ['start' => 1.0, 'end' => 2.0, 'text' => 'Real text.'],
        ];

        $vtt = VttFormatter::format($segments);

        // Only one cue block should be emitted — the blank-text segment contributes nothing
        $this->assertSame(1, substr_count($vtt, '-->'));
        $this->assertStringContainsString('Real text.', $vtt);
    }

    public function testFormatEmptySegmentsReturnsBareHeader(): void
    {
        $this->assertSame("WEBVTT\n", VttFormatter::format([]));
    }
}
