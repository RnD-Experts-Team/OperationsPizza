<?php

namespace App\Services\Tcp\Exceptions;

/** 401 (bad/expired token) or 403 (the API key lacks the required scope). */
class TcpAuthException extends TcpException
{
    public function errorCode(): string
    {
        return 'TCP_AUTH_FAILED';
    }
}
