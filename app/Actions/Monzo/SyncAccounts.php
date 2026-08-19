<?php

namespace App\Actions\Monzo;

use App\Contracts\MonzoClient;
use App\Models\Account;
use App\Models\BankConnection;
use Illuminate\Support\Collection;

/**
 * Mirrors the Monzo current account into the local accounts table.
 *
 * This app holds exactly two accounts, Monzo and Amex, so only the personal
 * current account is kept. Monzo also returns things like the rewards opt-in
 * account, which carries no transactions worth seeing here.
 *
 * Accounts connected inside the Monzo app, such as an AMEX card, are not
 * exposed by the Monzo API at all, which is why those arrive by CSV import.
 */
class SyncAccounts
{
    /**
     * The personal current account. Joint, business, flex and rewards accounts
     * are deliberately ignored.
     */
    private const CURRENT_ACCOUNT_TYPE = 'uk_retail';

    public function __construct(
        private readonly MonzoClient $monzo,
        private readonly EnsureFreshAccessToken $freshToken,
    ) {}

    /**
     * @return Collection<int, Account>
     */
    public function handle(BankConnection $connection): Collection
    {
        $accessToken = $this->freshToken->handle($connection);

        $payload = collect($this->monzo->accounts($accessToken))
            ->reject(fn (array $account): bool => (bool) ($account['closed'] ?? false))
            ->first(fn (array $account): bool => ($account['type'] ?? null) === self::CURRENT_ACCOUNT_TYPE);

        if ($payload === null) {
            return collect();
        }

        /**
         * Keyed on the owner alone, so a connection can only ever produce one
         * Monzo account no matter what the API returns.
         */
        $account = Account::query()->firstOrNew([
            'user_id' => $connection->user_id,
            'provider' => 'monzo',
        ]);

        $account->fill([
            'bank_connection_id' => $connection->id,
            'external_id' => (string) $payload['id'],
            /**
             * Monzo's own description for this account is an internal id, so
             * it is named here rather than taken from the API.
             */
            'name' => 'Monzo',
            'currency' => $this->stringOrNull($payload['currency'] ?? null) ?? 'GBP',
        ])->save();

        return collect([$account]);
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }
}
