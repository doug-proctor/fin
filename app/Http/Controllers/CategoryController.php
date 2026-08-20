<?php

namespace App\Http\Controllers;

use App\Http\Requests\Transactions\CategoryRequest;
use App\Http\Requests\Transactions\CategoryStoreRequest;
use App\Models\Category;
use App\Models\Transaction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class CategoryController extends Controller
{
    public function index(Request $request): Response
    {
        $userId = $request->user()->id;

        /** One query for the counts rather than one per category. */
        $counts = Transaction::query()
            ->where('user_id', $userId)
            ->whereNotNull('category')
            ->groupBy('category')
            ->pluck(DB::raw('COUNT(*)'), 'category');

        return Inertia::render('categories', [
            'categories' => Category::query()
                ->where('user_id', $userId)
                ->orderBy('label')
                ->get()
                ->map(fn (Category $category): array => [
                    'id' => $category->id,
                    'value' => $category->value,
                    'label' => $category->label,
                    'count' => (int) ($counts[$category->value] ?? 0),
                    /**
                     * Monzo prefixes its own ids; a value that still reads
                     * like one has never been given a name.
                     */
                    'isUnnamed' => $category->label === $category->value,
                ])
                ->all(),
        ]);
    }

    public function store(CategoryStoreRequest $request): RedirectResponse
    {
        Category::createCustom($request->user()->id, $request->validated('label'));

        return back();
    }

    public function update(CategoryRequest $request, Category $category): RedirectResponse
    {
        $category->update($request->validated());

        return back();
    }
}
