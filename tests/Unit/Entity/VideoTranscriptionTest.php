<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\VideoTranscription;
use PHPUnit\Framework\TestCase;

class VideoTranscriptionTest extends TestCase
{
    private const UPLOAD_ID = '550e8400-e29b-41d4-a716-446655440000';
    private const FILENAME = 'video.mp4';

    public function testSegmentsAndChaptersAreNullByDefault(): void
    {
        $transcription = new VideoTranscription(self::UPLOAD_ID, self::FILENAME);

        $this->assertNull($transcription->getSegments());
        $this->assertNull($transcription->getChapters());
    }

    public function testMarkCompletedStoresSegments(): void
    {
        $transcription = new VideoTranscription(self::UPLOAD_ID, self::FILENAME);
        $segments = [['start' => 0.0, 'end' => 5.0, 'text' => 'Hello.']];

        $transcription->markCompleted('Hello.', $segments);

        $this->assertSame('completed', $transcription->getStatus());
        $this->assertSame($segments, $transcription->getSegments());
        $this->assertNull($transcription->getChapters());
    }

    public function testMarkCompletedDefaultsSegmentsToEmptyArray(): void
    {
        $transcription = new VideoTranscription(self::UPLOAD_ID, self::FILENAME);

        $transcription->markCompleted('Hello.');

        $this->assertSame([], $transcription->getSegments());
    }

    public function testSetChaptersStoresChapters(): void
    {
        $transcription = new VideoTranscription(self::UPLOAD_ID, self::FILENAME);
        $chapters = [['title' => 'Intro', 'startSeconds' => 0.0, 'endSeconds' => 30.0]];

        $transcription->setChapters($chapters);

        $this->assertSame($chapters, $transcription->getChapters());
    }

    public function testSetChaptersDistinguishesEmptyFromNotYetGenerated(): void
    {
        $transcription = new VideoTranscription(self::UPLOAD_ID, self::FILENAME);

        $transcription->setChapters([]);

        $this->assertSame([], $transcription->getChapters());
        $this->assertNotNull($transcription->getChapters());
    }

    public function testLanguageIsNullByDefaultAndSetOnMarkCompleted(): void
    {
        $transcription = new VideoTranscription(self::UPLOAD_ID, self::FILENAME);
        $this->assertNull($transcription->getLanguage());

        $transcription->markCompleted('Hello.', [], 'english');

        $this->assertSame('english', $transcription->getLanguage());
    }

    public function testTranslationsAreNullUntilSet(): void
    {
        $transcription = new VideoTranscription(self::UPLOAD_ID, self::FILENAME);

        $this->assertNull($transcription->getTranslation('es'));
    }

    public function testSetTranslationStoresPerLanguageAndDoesNotAffectOtherLanguages(): void
    {
        $transcription = new VideoTranscription(self::UPLOAD_ID, self::FILENAME);
        $esSegments = [['start' => 0.0, 'end' => 5.0, 'text' => 'Hola.']];

        $transcription->setTranslation('es', $esSegments);

        $this->assertSame($esSegments, $transcription->getTranslation('es'));
        $this->assertNull($transcription->getTranslation('fr'));

        $frSegments = [['start' => 0.0, 'end' => 5.0, 'text' => 'Bonjour.']];
        $transcription->setTranslation('fr', $frSegments);

        $this->assertSame($esSegments, $transcription->getTranslation('es'));
        $this->assertSame($frSegments, $transcription->getTranslation('fr'));
    }
}
