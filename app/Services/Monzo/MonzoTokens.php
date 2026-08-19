<?php

namespace App\Services\Monzo;

use Illuminate\Support\Carbon;

/**
 * The result of an authorisation code exchange or a token refresh.
 */
readonly class MonzoTokens
{
    public function __construct(
        public string $accessToken,
        public ?string $refreshToken,
        public ?Carbon $expiresAt,
        public ?string $userId,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromResponse(array $payload): self
    {
        $expiresIn = $payload['expires_in'] ?? null;

        return new self(
            accessToken: (string) $payload['access_token'],
            refreshToken: isset($payload['refresh_token']) ? (string) $payload['refresh_token'] : null,
            expiresAt: is_numeric($expiresIn) ? Carbon::now()->addSeconds((int) $expiresIn) : null,
            userId: isset($payload['user_id']) ? (string) $payload['user_id'] : null,
        );
    }

    /**
     * Monzo only issues refresh tokens to confidential clients. Without one
     * the connection dies at the first expiry and cannot be repaired without
     * the user reconnecting, so it is worth surfacing.
     */
    public function isRefreshable(): bool
    {
        return $this->refreshToken !== null;
    }
}
