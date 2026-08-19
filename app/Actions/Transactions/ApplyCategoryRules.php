<?php

namespace App\Actions\Transactions;

use App\Models\CategoryRule;
use App\Models\Transaction;
use Illuminate\Support\Collection;

/**
 * Applies the user's categorisation rules. This is what gives AMEX rows the
 * same category taxonomy as Monzo rows, since Monzo will not share its own
 * categorisation of connected accounts.
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
        $applied = null;

        foreach ($this->rulesFor($transaction->user_id) as $rule) {
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

    /**
     * Re-run the rules over transactions that have already been stored,
     * skipping any the user has categorised by hand.
     *
     * @param  Collection<int, Transaction>  $transactions
     * @return int The number of transactions changed.
     */
    public function handleMany(Collection $transactions): int
    {
        $changed = 0;

        foreach ($transactions as $transaction) {
            if ($transaction->categorised_by === 'user' || $transaction->isOverridden('category')) {
                continue;
            }

            $this->handle($transaction);

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

    private function apply(CategoryRule $rule, Transaction $transaction): void
    {
        if ($rule->set_category !== null && ! $transaction->isOverridden('category')) {
            $transaction->category = $rule->set_category;
            $transaction->categorised_by = 'rule';
            $transaction->category_rule_id = $rule->id;
        }

        if ($rule->set_tags !== null && $rule->set_tags !== [] && ! $transaction->isOverridden('tags')) {
            $transaction->tags = array_values(array_unique([
                ...($transaction->tags ?? []),
                ...$rule->set_tags,
            ]));
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
