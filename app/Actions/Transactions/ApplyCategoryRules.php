<?php

namespace App\Actions\Transactions;

use App\Models\CategoryRule;
use App\Models\Transaction;
use App\Support\Transactions\TransactionData;
use Illuminate\Support\Collection;

/**
 * Applies the user's categorisation rules. No bank's categories are read, so
 * these rules are the only thing that files a transaction automatically;
 * anything they do not match stays uncategorised until the user says.
 */
class ApplyCategoryRules
{
    /**
     * Rules are loaded once per run rather than per transaction, so importing
     * a long statement does not re-query for every row.
     *
     * @var Collection<int, CategoryRule>|null
     */
    private ?Collection $rules = null;

    private ?int $rulesUserId = null;

    /**
     * Apply the first matching rule to an unsaved or saved transaction.
     * Returns the rule that matched, if any.
     */
    public function handle(Transaction $transaction): ?CategoryRule
    {
        return $this->applyRules($transaction, $this->rulesFor($transaction->user_id));
    }

    /**
     * Re-run the rules over transactions that have already been stored,
     * skipping any the user has categorised by hand.
     *
     * @param  Collection<int, Transaction>  $transactions
     * @param  CategoryRule|null  $only  Run just this rule, ignoring the rest.
     * @return int The number of transactions changed.
     */
    public function handleMany(Collection $transactions, ?CategoryRule $only = null): int
    {
        $changed = 0;

        foreach ($transactions as $transaction) {
            if ($transaction->categorised_by === 'user' || $transaction->isOverridden('category')) {
                continue;
            }

            $this->applyRules($transaction, $only === null
                ? $this->rulesFor($transaction->user_id)
                : new Collection([$only]));

            if ($transaction->isDirty()) {
                $transaction->save();
                $changed++;
            }
        }

        return $changed;
    }

    /**
     * Drop the cached rule set, so a run that follows a rule edit sees it.
     */
    public function flush(): void
    {
        $this->rules = null;
        $this->rulesUserId = null;
    }

    /**
     * Run a given set of rules over one transaction, in the order given.
     *
     * @param  Collection<int, CategoryRule>  $rules
     */
    private function applyRules(Transaction $transaction, Collection $rules): ?CategoryRule
    {
        $applied = null;

        foreach ($rules as $rule) {
            if (! $rule->matches($transaction)) {
                continue;
            }

            $this->apply($rule, $transaction);
            $applied = $rule;

            if ($rule->stops_processing) {
                break;
            }
        }

        return $applied;
    }

    private function apply(CategoryRule $rule, Transaction $transaction): void
    {
        if ($rule->set_category !== null && ! $transaction->isOverridden('category')) {
            $transaction->category = $rule->set_category;
            $transaction->categorised_by = 'rule';
            $transaction->category_rule_id = $rule->id;
        }

        /**
         * A rename is the rule's, not the user's, so it is not recorded as an
         * override: a re-sync refreshes the bank's name and the rule renames
         * it again on the way past.
         */
        if ($rule->set_name !== null && $rule->set_name !== '' && ! $transaction->isOverridden('name')) {
            $transaction->name = $rule->set_name;
        }

        /**
         * Normalised on the way past as well as when the rule was saved, so a
         * rule written before that existed cannot land a second spelling of a
         * tag the row already carries — array_unique only dedupes what is
         * spelled the same way.
         */
        if ($rule->set_tags !== null && $rule->set_tags !== [] && ! $transaction->isOverridden('tags')) {
            $transaction->tags = TransactionData::normaliseTags([
                ...($transaction->tags ?? []),
                ...$rule->set_tags,
            ]);
        }
    }

    /**
     * @return Collection<int, CategoryRule>
     */
    private function rulesFor(int $userId): Collection
    {
        if ($this->rules === null || $this->rulesUserId !== $userId) {
            $this->rules = CategoryRule::query()
                ->where('user_id', $userId)
                ->activeInPriorityOrder()
                ->get();

            $this->rulesUserId = $userId;
        }

        return $this->rules;
    }
}
