<?php

namespace App\Services\Humanity\Exceptions;

/**
 * Statuses 2/3 (bad key / expired token), 7 and 20 (insufficient permission),
 * and -1/-2/-3 (key flagged or banned).
 *
 * A permission failure usually means the service account's role is below the
 * level the endpoint requires — POST /shifts needs Supervisor (3) or better.
 */
class HumanityAuthException extends HumanityException
{
    public function errorCode(): string
    {
        return 'HUMANITY_AUTH_FAILED';
    }
}
