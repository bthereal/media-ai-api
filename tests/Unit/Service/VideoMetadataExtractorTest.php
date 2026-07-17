<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\VideoMetadataExtractor;
use PHPUnit\Framework\TestCase;

class VideoMetadataExtractorTest extends TestCase
{
    private VideoMetadataExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new VideoMetadataExtractor();
    }

    public function testExtractDurationReturnsNullForNonVideoFile(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'test_meta_');
        file_put_contents($tmpFile, 'this is not a video file');

        try {
            $result = $this->extractor->extractDuration($tmpFile);
            $this->assertNull($result);
        } finally {
            unlink($tmpFile);
        }
    }

    public function testExtractDurationReturnsNullForNonExistentFile(): void
    {
        $result = $this->extractor->extractDuration('/tmp/does-not-exist-12345.mp4');
        $this->assertNull($result);
    }
}
