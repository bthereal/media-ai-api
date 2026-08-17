<?php

declare(strict_types=1);

namespace App\Message;

final readonly class GenerateCaptionsMessage
{
    public function __construct(
        public string $contentId,
    ) {
    }
}
