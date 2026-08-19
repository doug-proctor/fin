<?php

namespace App\Http\Controllers;

use App\Actions\Transactions\ApplyCategoryRules;
use App\Http\Requests\Transactions\CategoryRuleRequest;
use App\Models\Account;
use App\Models\Category;
use App\Models\CategoryRule;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CategoryRuleController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $labels = Category::labelsFor($user->id);

        return Inertia::render('transactions/rules', [
            /** Inactive rules are listed too, in the order they would run. */
            'rules' => CategoryRule::query()
                ->where('user_id', $user->id)
                ->orderByDesc('priority')
                ->orderBy('id')
                ->get()
                ->map(fn (CategoryRule $rule): array => [
                    'id' => $rule->id,
                    'name' => $rule->name,
                    'matchField' => $rule->match_field,
                    'matchType' => $rule->match_type,
                    'matchValue' => $rule->match_value,
                    'accountId' => $rule->account_id,
                    'amountMinMinor' => $rule->amount_min_minor,
                    'amountMaxMinor' => $rule->amount_max_minor,
                    'amountMinor' => $rule->amount_minor,
                    'bookedOn' => $rule->booked_on?->toDateString(),
                    'setCategory' => $rule->set_category,
                    'setCategoryLabel' => $rule->set_category === null
                        ? null
                        : ($labels[$rule->set_category] ?? $rule->set_category),
                    'setTags' => $rule->set_tags ?? [],
                    'priority' => $rule->priority,
                    'stopsProcessing' => $rule->stops_processing,
                    'isActive' => $rule->is_active,
                ])
                ->all(),
            'accounts' => Account::query()
                ->where('user_id', $user->id)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->all(),
            'categories' => $this->categoryOptions($request->user()->id),
            'matchFields' => CategoryRule::MATCH_FIELDS,
            'matchTypes' => CategoryRule::MATCH_TYPES,
            'uncategorisedCount' => Transaction::query()
                ->where('user_id', $user->id)
                ->whereNull('category')
                ->count(),
            /**
             * How many rows a re-apply could change: everything except the
             * ones categorised by hand, which the rules never touch.
             */
            'recategorisableCount' => Transaction::query()
                ->where('user_id', $user->id)
                ->where($this->notCategorisedByHand(...))
                ->count(),
        ]);
    }

    public function store(CategoryRuleRequest $request): RedirectResponse
    {
        CategoryRule::query()->create([
            ...$request->validated(),
            'user_id' => $request->user()->id,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Rule created.')]);

        return to_route('category-rules.index');
    }

    public function update(CategoryRuleRequest $request, CategoryRule $categoryRule): RedirectResponse
    {
        $categoryRule->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Rule updated.')]);

        return to_route('category-rules.index');
    }

    public function destroy(Request $request, CategoryRule $categoryRule): RedirectResponse
    {
        abort_unless($categoryRule->user_id === $request->user()->id, 403);

        $categoryRule->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Rule deleted.')]);

        return to_route('category-rules.index');
    }

    /**
     * Run the rules back over transactions already stored.
     *
     * Rows the user categorised by hand are left alone, so writing a rule
     * after the fact can never undo a correction already made.
     */
    public function apply(Request $request, ApplyCategoryRules $applyCategoryRules): RedirectResponse
    {
        $onlyUncategorised = $request->boolean('only_uncategorised', true);

        $applyCategoryRules->flush();

        $changed = 0;

        Transaction::query()
            ->where('user_id', $request->user()->id)
            ->where($this->notCategorisedByHand(...))
            ->when($onlyUncategorised, fn ($query) => $query->whereNull('category'))
            ->chunkById(500, function ($transactions) use ($applyCategoryRules, &$changed): void {
                $changed += $applyCategoryRules->handleMany($transactions);
            });

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => trans_choice(
                '{0}No transactions matched your rules.|[1,*]:count transactions recategorised.',
                $changed,
                ['count' => $changed],
            ),
        ]);

        return back();
    }

    /**
     * Rows the rules are allowed to change. A category the user set by hand is
     * theirs, so it is excluded both from the count shown on the confirmation
     * and from the pass that follows it.
     *
     * @param  Builder<Transaction>  $query
     */
    private function notCategorisedByHand(Builder $query): void
    {
        $query->whereNull('categorised_by')->orWhere('categorised_by', '!=', 'user');
    }
}
