<?php

namespace App\Actions\Monzo;

use App\Contracts\MonzoClient;
use App\Exceptions\Monzo\TokenExpiredException;
use App\Models\BankConnection;

/**
 * Hands back a usable access token, refreshing it first if it is at or near
 * expiry.
 */
class EnsureFreshAccessToken
{
    public function __construct(private readonly MonzoClient $monzo) {}

    public function handle(BankConnection $connection): string
    {
        if ($connection->access_token === null) {
            throw TokenExpiredException::make();
        }

        if (! $connection->needsRefresh()) {
            return $connection->access_token;
        }

        /**
         * Monzo only issues refresh tokens to confidential clients. Without
         * one there is nothing to refresh and the user has to reconnect.
         */
        if ($connection->refresh_token === null) {
            throw TokenExpiredException::make();
        }

        $tokens = $this->monzo->refreshTokens($connection->refresh_token);

        $connection->forceFill([
            'access_token' => $tokens->accessToken,
            'refresh_token' => $tokens->refreshToken ?? $connection->refresh_token,
            'expires_at' => $tokens->expiresAt,
        ])->save();

        return $tokens->accessToken;
    }
}
