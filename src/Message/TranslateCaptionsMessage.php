<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Dispatched the first time a viewer's player requests a specific caption
 * language that hasn't been translated yet — see ContentController::captions().
 * Deliberately per-language, not per-video: only the languages someone actually
 * asks for ever get translated, unlike the old upload-time "pick languages
 * eagerly for the whole catalog" design.
 */
final readonly class TranslateCaptionsMessage
{
    public function __construct(
        public string $contentId,
        public string $lang,
    ) {
    }
}
