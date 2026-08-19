<?php

namespace App\Exceptions\Monzo;

/**
 * Monzo answers 403 until the user approves the strong customer
 * authentication push notification in their app. This is a normal step in the
 * connect flow rather than an error, so it gets its own type and the UI waits
 * for approval instead of tearing the connection down.
 */
class ScaRequiredException extends MonzoException
{
    public static function make(): self
    {
        return new self('Monzo access is pending approval in the Monzo app.');
    }
}
