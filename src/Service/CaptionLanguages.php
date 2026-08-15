<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Single source of truth for caption language codes/labels — used both to normalize
 * Whisper's detected language name into an ISO 639-1 code, and to curate the small,
 * fixed set of languages offered as translation targets.
 */
final class CaptionLanguages
{
    /** @var array<string, string> ISO 639-1 code => display label */
    public const array LABELS = [
        'en' => 'English',
        'es' => 'Spanish',
        'fr' => 'French',
        'de' => 'German',
        'it' => 'Italian',
        'pt' => 'Portuguese',
        'ja' => 'Japanese',
        'zh' => 'Chinese',
        'ko' => 'Korean',
        'ru' => 'Russian',
        'nl' => 'Dutch',
        'ar' => 'Arabic',
        'hi' => 'Hindi',
        'tr' => 'Turkish',
        'pl' => 'Polish',
        'sv' => 'Swedish',
    ];

    /** @var list<string> curated set of codes offered as translation targets */
    public const array TRANSLATION_TARGETS = ['es', 'fr', 'de', 'ja', 'zh'];

    public static function codeForWhisperLanguage(string $whisperLanguageName): string
    {
        $normalized = strtolower(trim($whisperLanguageName));

        foreach (self::LABELS as $code => $label) {
            if (strtolower($label) === $normalized) {
                return $code;
            }
        }

        return '' !== $normalized ? substr($normalized, 0, 2) : 'en';
    }

    public static function labelFor(string $code): string
    {
        return self::LABELS[$code] ?? strtoupper($code);
    }
}
