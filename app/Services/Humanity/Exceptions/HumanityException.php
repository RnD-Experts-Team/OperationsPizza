<?php

namespace App\Services\Humanity\Exceptions;

use RuntimeException;

class HumanityException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $humanityStatus = null,
        public readonly ?int $httpStatus = null,
        public readonly ?array $context = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function errorCode(): string
    {
        return 'HUMANITY_ERROR';
    }
}
