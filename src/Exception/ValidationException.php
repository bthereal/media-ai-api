<?php

declare(strict_types=1);

namespace App\Exception;

class ValidationException extends ChunkUploadException
{
    public function __construct(string $message, private readonly int $httpStatus)
    {
        parent::__construct($message);
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }
}
