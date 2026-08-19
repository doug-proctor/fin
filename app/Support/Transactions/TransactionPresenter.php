<?php

namespace App\Support\Transactions;

use App\Models\Category;
use App\Models\Transaction;

/**
 * Shapes a transaction for the table. Money stays in minor units all the way
 * to the browser so no rounding can creep in; formatting is the frontend's
 * job.
 */
class TransactionPresenter
{
    /**
     * @param  array<string, string>  $categoryLabels  Value to display name.
     */
    public function __construct(
        private readonly TransactionQuery $query,
        private readonly array $categoryLabels = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function __invoke(Transaction $transaction): array
    {
        return [
            'id' => $transaction->id,
            'bookedAt' => $transaction->booked_at->toIso8601String(),
            'name' => $transaction->name,
            'description' => $transaction->description,
            'category' => $transaction->category,
            /** Falls back to the raw value for a category not yet named. */
            'categoryLabel' => $transaction->category === null
                ? null
                : ($this->categoryLabels[$transaction->category] ?? $transaction->category),
            'amountMinor' => $transaction->amount_minor,
            'moneyInMinor' => $transaction->money_in_minor,
            'moneyOutMinor' => $transaction->money_out_minor,
            'currency' => $transaction->currency,
            'type' => $transaction->type,
            'merchantName' => $transaction->merchant_name,
            'notes' => $transaction->notes,
            'tags' => $transaction->tags ?? [],
            'accountId' => $transaction->account_id,
            'accountName' => $transaction->account->name,
            'accountProvider' => $transaction->account->provider,
            'categorisedBy' => $transaction->categorised_by,
            /** Lets the table mark a row the totals deliberately leave out. */
            'excludedFromTotals' => Category::isExcludedFromTotals($transaction->category),
            /** Which fields the user has taken ownership of, so the UI can mark them. */
            'overriddenFields' => array_keys(array_filter($transaction->overrides ?? [])),
            'groupKey' => $this->query->groupKeyFor($transaction),
        ];
    }
}
