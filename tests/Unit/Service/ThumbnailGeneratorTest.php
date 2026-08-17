<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\ThumbnailGenerator;
use PHPUnit\Framework\TestCase;

class ThumbnailGeneratorTest extends TestCase
{
    public function testCandidateSeekTimesUsesFixedFallbackWhenDurationUnknown(): void
    {
        $this->assertSame([1.0, 0.0], ThumbnailGenerator::candidateSeekTimes(null));
    }

    public function testCandidateSeekTimesUsesFixedFallbackWhenDurationZeroOrNegative(): void
    {
        $this->assertSame([1.0, 0.0], ThumbnailGenerator::candidateSeekTimes(0.0));
        $this->assertSame([1.0, 0.0], ThumbnailGenerator::candidateSeekTimes(-5.0));
    }

    public function testCandidateSeekTimesUsesPercentagesOfDuration(): void
    {
        $times = ThumbnailGenerator::candidateSeekTimes(100.0);

        $this->assertSame([10.0, 30.0, 50.0, 70.0], $times);
    }

    public function testCandidateSeekTimesDedupesForShortVideos(): void
    {
        // At 1 second total, all four percentage marks round to the same values or fewer distinct ones
        $times = ThumbnailGenerator::candidateSeekTimes(1.0);

        $this->assertSame(array_values(array_unique($times)), $times);
        $this->assertNotEmpty($times);
    }
}
