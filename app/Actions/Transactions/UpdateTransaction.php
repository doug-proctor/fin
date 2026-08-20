<?php

namespace App\Actions\Transactions;

use App\Models\Transaction;
use App\Support\Transactions\TransactionData;

/**
 * Applies a hand edit.
 *
 * Every bank-owned field the user touches is recorded in the overrides map, so
 * the next sync leaves it alone. That is what makes the table safe to correct
 * without the bank quietly undoing the correction.
 */
class UpdateTransaction
{
    /**
     * @param  array<string, mixed>  $attributes  Bank fields the user edited.
     */
    public function handle(Transaction $transaction, array $attributes): Transaction
    {
        if ($attributes !== []) {
            $transaction->fill($attributes);

            /**
             * Editing the notes rewrites the tags too, unless tags were set in
             * the same edit, so the "#tag" convention keeps working.
             */
            if (array_key_exists('notes', $attributes) && ! array_key_exists('tags', $attributes)) {
                $transaction->tags = TransactionData::parseTags($transaction->notes);
                $attributes['tags'] = $transaction->tags;
            }

            /**
             * Only bank-owned fields are recorded. An override exists to stop
             * an import writing over a correction, so a column no import
             * touches has nothing to protect, and an edit that touched none of
             * them leaves the map alone rather than writing an empty one.
             */
            $overridden = array_values(array_intersect(array_keys($attributes), Transaction::BANK_FIELDS));

            if ($overridden !== []) {
                $transaction->markOverridden($overridden);
            }

            /**
             * A category chosen by hand outranks both the provider's guess and
             * any rule, and must not be revisited by the rules engine.
             */
            if (array_key_exists('category', $attributes)) {
                $transaction->categorised_by = 'user';
                $transaction->category_rule_id = null;
            }
        }

        $transaction->save();

        return $transaction;
    }
}
