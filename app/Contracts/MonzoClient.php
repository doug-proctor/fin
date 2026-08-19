<?php

namespace App\Contracts;

use App\Services\Monzo\MonzoTokens;
use Generator;

/**
 * The boundary to the Monzo API. Coded to an interface so the sync and import
 * paths can be tested without reaching the network.
 */
interface MonzoClient
{
    /**
     * The URL to send the user to in order to begin the OAuth flow.
     */
    public function authorizationUrl(string $state): string;

    public function exchangeCode(string $code): MonzoTokens;

    public function refreshTokens(string $refreshToken): MonzoTokens;

    /**
     * @return array<string, mixed>
     */
    public function whoami(string $accessToken): array;

    /**
     * Monzo's own accounts only. Accounts connected through Monzo Plus, such
     * as an AMEX card, are deliberately not exposed by this API.
     *
     * @return array<int, array<string, mixed>>
     */
    public function accounts(string $accessToken): array;

    /**
     * Transactions for one account, newest page last, yielded one at a time so
     * a long backfill never holds the whole history in memory.
     *
     * @param  string|null  $since  An ISO-8601 timestamp or a transaction id.
     * @return Generator<int, array<string, mixed>>
     */
    public function transactions(string $accessToken, string $accountId, ?string $since = null): Generator;

    /**
     * @return array<string, mixed>
     */
    public function balance(string $accessToken, string $accountId): array;
}
