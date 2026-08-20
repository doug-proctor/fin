<?php

namespace App\Http\Controllers;

use App\Actions\Transactions\UpdateTransaction;
use App\Http\Requests\Transactions\TransactionUpdateRequest;
use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Support\Transactions\TransactionFilters;
use App\Support\Transactions\TransactionPresenter;
use App\Support\Transactions\TransactionQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TransactionController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $filters = TransactionFilters::fromArray($request->query());
        $query = new TransactionQuery($user->id, $filters);

        $present = new TransactionPresenter($query, Category::labelsFor($user->id));

        return Inertia::render('transactions', [
            'transactions' => $query->rows()->map($present)->all(),
            /**
             * The table shows one month at a time, so this is the paging
             * control. A null next is what disables the forward arrow.
             */
            'month' => [
                'label' => $filters->monthStart()->format('F Y'),
                'current' => $filters->monthStart()->format('Y-m'),
                'previous' => $filters->previousMonth()->format('Y-m'),
                'next' => $filters->nextMonth()?->format('Y-m'),
            ],
            /**
             * Rows in this month still to be marked off, counted over the
             * whole month rather than the filtered table, because that is
             * what "Mark all as processed" would write to.
             */
            'unprocessedCount' => $query->monthUnprocessed()->count(),
            'summary' => $query->summary(),
            /**
             * Cast to objects before serialising. An empty PHP array encodes
             * as a JSON array, so a view with no filters would reach the
             * browser as [] rather than {}, and reading a key off it would
             * find an Array method instead of undefined.
             */
            'subtotals' => (object) $query->groupSubtotals(),
            'filters' => (object) $filters->toQuery(),
            'facets' => $query->facets(),
            'accounts' => Account::query()
                ->where('user_id', $user->id)
                ->orderBy('name')
                ->get(['id', 'name', 'provider'])
                ->all(),
            /** Drives whether the Import Monzo button has anything to talk to. */
            'monzoConnected' => (bool) $user->monzoConnection?->isActive(),
            /**
             * Set only on the redirect that follows an AMEX upload, so the
             * dialog that started it can report how it went.
             */
            'importResult' => $request->session()->get('importResult'),
            'options' => [
                'categories' => $this->categoryOptions($request->user()->id),
            ],
        ]);
    }

    /**
     * Apply a hand edit to one transaction.
     */
    public function update(
        TransactionUpdateRequest $request,
        Transaction $transaction,
        UpdateTransaction $update,
    ): RedirectResponse {
        $update->handle($transaction, $request->editedFields(), $request->processedFlag());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Transaction updated.')]);

        return back();
    }

    /**
     * Mark off every unprocessed transaction in one month.
     *
     * Scoped to the month on screen and nothing else: the filter bar does not
     * narrow it, so what the button writes to is always the month named in the
     * confirmation, whatever the table happens to be showing.
     */
    public function markProcessed(Request $request): RedirectResponse
    {
        $filters = TransactionFilters::fromArray(['month' => $request->input('month')]);
        $query = new TransactionQuery($request->user()->id, $filters);

        $marked = $query->monthUnprocessed()->update(['processed' => true]);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => trans_choice(
                '{0}Nothing left to mark in :month.|{1}1 transaction in :month marked as processed.|[2,*]:count transactions in :month marked as processed.',
                $marked,
                ['count' => $marked, 'month' => $filters->monthStart()->format('F Y')],
            ),
        ]);

        return back();
    }
}
