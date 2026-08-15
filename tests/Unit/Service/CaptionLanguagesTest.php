<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\CaptionLanguages;
use PHPUnit\Framework\TestCase;

class CaptionLanguagesTest extends TestCase
{
    public function testCodeForWhisperLanguageMatchesKnownName(): void
    {
        $this->assertSame('en', CaptionLanguages::codeForWhisperLanguage('english'));
        $this->assertSame('es', CaptionLanguages::codeForWhisperLanguage('Spanish'));
        $this->assertSame('ja', CaptionLanguages::codeForWhisperLanguage('JAPANESE'));
    }

    public function testCodeForWhisperLanguageFallsBackToFirstTwoLettersForUnknownName(): void
    {
        $this->assertSame('kl', CaptionLanguages::codeForWhisperLanguage('klingon'));
    }

    public function testLabelForKnownCode(): void
    {
        $this->assertSame('Spanish', CaptionLanguages::labelFor('es'));
    }

    public function testLabelForUnknownCodeReturnsUppercasedCode(): void
    {
        $this->assertSame('XX', CaptionLanguages::labelFor('xx'));
    }

    public function testTranslationTargetsAreAllKnownLabels(): void
    {
        foreach (CaptionLanguages::TRANSLATION_TARGETS as $code) {
            $this->assertArrayHasKey($code, CaptionLanguages::LABELS);
        }
    }
}
