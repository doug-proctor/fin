<?php

namespace App\Actions\Monzo;

use App\Actions\Transactions\UpsertTransaction;
use App\Contracts\MonzoClient;
use App\Models\Account;
use App\Models\BankConnection;
use App\Support\Transactions\TransactionData;
use Illuminate\Support\Carbon;

/**
 * Pulls transactions for one Monzo account, from a given point up to now.
 *
 * The caller decides how far back to reach. Every run re-reads its whole
 * span rather than starting where the last one stopped, so a row whose
 * merchant or description changed at the bank is corrected here. At a few
 * hundred transactions that is three requests instead of one, and it removes
 * any way for a stored row to drift and never be looked at again.
 *
 * An unbounded request is never made. Past the five minute window Monzo
 * answers one with a short recent slice and a 200, which looks like success
 * and is not.
 */
class SyncTransactions
{
    /**
     * The widest window Monzo will serve an established connection. One day
     * inside the documented 90 so a slow request cannot cross the boundary
     * mid-flight and earn a 403. Anything wider is refused outright: 91 days
     * answers forbidden.verification_required.
     */
    public const RECENT_WINDOW_DAYS = 89;

    /**
     * The date the very first pull asks from. Chosen by hand rather than
     * counted back from today, so the ledger always starts on the same day
     * however long after setup the first pull happens.
     *
     * Only reachable inside the five minutes after authorising. After that
     * Monzo refuses anything older than 90 days and the caller settles for
     * the recent window.
     */
    public const INITIAL_START_DATE = '2026-01-01';

    public function __construct(
        private readonly MonzoClient $monzo,
        private readonly EnsureFreshAccessToken $freshToken,
        private readonly UpsertTransaction $upsert,
    ) {}

    public function handle(BankConnection $connection, Account $account, Carbon $since): SyncSummary
    {
        $summary = $this->read($connection, $account, $since);

        $account->forceFill(['last_synced_at' => Carbon::now()])->save();

        return $summary;
    }

    public static function recentWindowFloor(): Carbon
    {
        return Carbon::now()->subDays(self::RECENT_WINDOW_DAYS);
    }

    public static function initialStart(): Carbon
    {
        return Carbon::parse(self::INITIAL_START_DATE)->startOfDay();
    }

    private function read(BankConnection $connection, Account $account, Carbon $since): SyncSummary
    {
        $accessToken = $this->freshToken->handle($connection);

        $created = 0;
        $updated = 0;
        $oldest = null;
        $newest = null;

        foreach ($this->monzo->transactions($accessToken, (string) $account->external_id, $since->toIso8601ZuluString()) as $payload) {
            if (TransactionData::isDeclined($payload)) {
                continue;
            }

            $data = TransactionData::fromMonzo($payload);
            $result = $this->upsert->handle($account, $data);

            if (! $result->created) {
                $updated++;

                continue;
            }

            $created++;
            $oldest = $oldest === null ? $data->bookedAt : $oldest->min($data->bookedAt);
            $newest = $newest === null ? $data->bookedAt : $newest->max($data->bookedAt);
        }

        return new SyncSummary($created, $updated, $oldest, $newest);
    }
}
