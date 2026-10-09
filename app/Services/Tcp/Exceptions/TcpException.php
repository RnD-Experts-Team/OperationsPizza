<?php

namespace App\Services\Tcp\Exceptions;

use RuntimeException;

class TcpException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $httpStatus = null,
        public readonly array $errors = [],
        public readonly ?string $requestId = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function errorCode(): string
    {
        return 'TCP_ERROR';
    }
}
