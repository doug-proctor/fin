<?php

namespace App\Actions\Transactions;

use App\Models\Account;
use App\Models\AmexSyncReport;
use App\Models\Category;
use App\Models\Transaction;
use App\Support\Transactions\TransactionData;

/**
 * The single write path into the transactions table. Every source, whether the
 * Monzo API or an AMEX CSV, lands here, which is what keeps the two accounts
 * sharing one shape and one set of dedupe guarantees.
 */
class UpsertTransaction
{
    public function __construct(private readonly ApplyCategoryRules $applyCategoryRules) {}

    /**
     * Create the transaction, or refresh the bank-owned fields of an existing
     * one. Fields the user has edited by hand are never written over.
     */
    public function handle(
        Account $account,
        TransactionData $data,
        ?AmexSyncReport $batch = null,
    ): UpsertResult {
        $existing = Transaction::query()
            ->where('account_id', $account->id)
            ->where('dedupe_hash', $data->dedupeHash)
            ->first();

        /**
         * A category the user made in the Monzo app arrives as an id with no
         * name attached, so it is registered on the way past. Without this it
         * would have nothing to display and would read as uncategorised.
         */
        if ($data->category !== null) {
            Category::ensure($account->user_id, $data->category);
        }

        if ($existing === null) {
            return new UpsertResult($this->create($account, $data, $batch), created: true);
        }

        return new UpsertResult($this->refresh($existing, $data), created: false);
    }

    private function create(Account $account, TransactionData $data, ?AmexSyncReport $batch): Transaction
    {
        $transaction = new Transaction($data->bankAttributes());

        $transaction->fill([
            'user_id' => $account->user_id,
            'account_id' => $account->id,
            'amex_sync_report_id' => $batch?->id,
            'external_id' => $data->externalId,
            'dedupe_hash' => $data->dedupeHash,
            'categorised_by' => $data->category !== null ? 'source' : null,
        ]);

        $this->applyCategoryRules->handle($transaction);

        $transaction->save();

        return $transaction;
    }

    /**
     * A re-sync refreshes what the bank owns. Anything the user has taken
     * ownership of is filtered out before the write, so re-importing an
     * overlapping statement can never undo a manual correction.
     */
    private function refresh(Transaction $transaction, TransactionData $data): Transaction
    {
        $transaction->fill($transaction->withoutOverridden($data->bankAttributes()));

        if ($transaction->external_id === null && $data->externalId !== null) {
            $transaction->external_id = $data->externalId;
        }

        /**
         * Only rows the user has not categorised themselves are offered back
         * to the rules engine.
         */
        if ($transaction->categorised_by !== 'user') {
            $this->applyCategoryRules->handle($transaction);
        }

        $transaction->save();

        return $transaction;
    }
}
