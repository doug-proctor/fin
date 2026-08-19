<?php

namespace App\Actions\Monzo;

use App\Exceptions\Monzo\ScaRequiredException;
use App\Models\Account;
use App\Models\BankConnection;
use App\Models\MonzoSyncReport;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Syncs every account on a connection.
 *
 * Monzo allows one active access token per user, so two overlapping runs would
 * invalidate each other's tokens. A lock keeps them apart.
 */
class SyncMonzoConnection
{
    public function __construct(
        private readonly SyncAccounts $syncAccounts,
        private readonly SyncTransactions $syncTransactions,
    ) {}

    /**
     * @param  bool  $initial  Reach back six months rather than the usual
     *                         window. Only Monzo's five minute post-auth
     *                         window allows it; afterwards it is refused and
     *                         the run settles for the recent window.
     */
    public function handle(BankConnection $connection, bool $initial = false): SyncSummary
    {
        $lock = Cache::lock('monzo-sync-'.$connection->id, 600);

        if (! $lock->get()) {
            throw new RuntimeException('A sync is already running for this connection.');
        }

        try {
            return $this->sync($connection, $initial);
        } finally {
            $lock->release();
        }
    }

    private function sync(BankConnection $connection, bool $initial): SyncSummary
    {
        /**
         * Read before the run overwrites it, because the gap is measured
         * against where the previous run finished.
         */
        $previousSyncedAt = $connection->last_synced_at;

        $report = MonzoSyncReport::query()->create([
            'user_id' => $connection->user_id,
            'status' => MonzoSyncReport::STATUS_COMPLETED,
            'started_at' => Carbon::now(),
        ]);

        try {
            $accounts = $this->syncAccounts->handle($connection);

            /**
             * Reaching the accounts endpoint at all proves the user has
             * approved the strong customer authentication push.
             */
            if ($connection->sca_confirmed_at === null) {
                $connection->forceFill(['sca_confirmed_at' => Carbon::now()])->save();
                $connection->refresh();
            }

            $summary = new SyncSummary;
            $since = SyncTransactions::recentWindowFloor();

            foreach ($accounts as $account) {
                [$accountSummary, $reached] = $this->syncAccount($connection, $account, $initial);

                $summary = $summary->plus($accountSummary);
                $since = $since->min($reached);
            }

            $connection->forceFill([
                'last_synced_at' => Carbon::now(),
                'last_sync_error' => null,
            ])->save();

            [$gapFrom, $gapTo] = $this->gap($previousSyncedAt, $since);

            $report->forceFill([
                'imported' => $summary->created,
                'oldest_booked_at' => $summary->oldestImported,
                'newest_booked_at' => $summary->newestImported,
                'gap_from' => $gapFrom,
                'gap_to' => $gapTo,
                'finished_at' => Carbon::now(),
            ])->save();

            return $summary;
        } catch (Throwable $exception) {
            /**
             * Recorded before rethrowing, so a run that never worked is on the
             * list rather than looking like a night with no transactions.
             */
            $report->forceFill([
                'status' => MonzoSyncReport::STATUS_FAILED,
                'error' => $exception->getMessage(),
                'finished_at' => Carbon::now(),
            ])->save();

            if ($exception instanceof ScaRequiredException) {
                throw $exception;
            }

            Log::error('Monzo sync failed.', [
                'connection_id' => $connection->id,
                'initial' => $initial,
                'exception' => $exception->getMessage(),
            ]);

            $connection->forceFill(['last_sync_error' => $exception->getMessage()])->save();

            throw $exception;
        }
    }

    /**
     * Sync one account, and report how far back the run actually reached.
     *
     * A first pull asks from a fixed start date. Outside Monzo's five minute window
     * that is refused, and since reaching the accounts endpoint already
     * proved the push was approved, the refusal can only mean the request was
     * too wide, so it is retried at the width Monzo will serve.
     *
     * @return array{0: SyncSummary, 1: Carbon}
     */
    private function syncAccount(BankConnection $connection, Account $account, bool $initial): array
    {
        $recent = SyncTransactions::recentWindowFloor();

        if (! $initial) {
            return [$this->syncTransactions->handle($connection, $account, $recent), $recent];
        }

        $initialStart = SyncTransactions::initialStart();

        try {
            return [$this->syncTransactions->handle($connection, $account, $initialStart), $initialStart];
        } catch (ScaRequiredException $exception) {
            if ($connection->sca_confirmed_at === null) {
                throw $exception;
            }

            Log::info('Monzo refused the request for the full start date; settling for the recent window.', [
                'connection_id' => $connection->id,
            ]);

            return [$this->syncTransactions->handle($connection, $account, $recent), $recent];
        }
    }

    /**
     * The span between where the previous run finished and the oldest point
     * this one could reach. Transactions dated inside it were never offered
     * to either run and Monzo will not serve them now.
     *
     * @return array{0: ?CarbonInterface, 1: ?CarbonInterface}
     */
    private function gap(?CarbonInterface $previousSyncedAt, CarbonInterface $reachedBackTo): array
    {
        if ($previousSyncedAt === null || $previousSyncedAt->greaterThanOrEqualTo($reachedBackTo)) {
            return [null, null];
        }

        return [$previousSyncedAt, $reachedBackTo];
    }
}
