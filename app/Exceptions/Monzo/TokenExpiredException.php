<?php

namespace App\Exceptions\Monzo;

/**
 * The access token is no longer valid and could not be refreshed, so the user
 * has to reconnect.
 */
class TokenExpiredException extends MonzoException
{
    public static function make(): self
    {
        return new self('The Monzo access token has expired and could not be refreshed.');
    }
}
