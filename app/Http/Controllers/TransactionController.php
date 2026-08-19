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

        return Inertia::render('transactions/index', [
            'transactions' => $query->rows()->map($present)->all(),
            /**
             * The table shows one month at a time, so this is the paging
             * control. A null next is what disables the forward arrow.
             */
            'month' => [
                'label' => $filters->monthStart()->format('F Y'),
                'previous' => $filters->previousMonth()->format('Y-m'),
                'next' => $filters->nextMonth()?->format('Y-m'),
            ],
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
        $update->handle($transaction, $request->editedFields());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Transaction updated.')]);

        return back();
    }
}
