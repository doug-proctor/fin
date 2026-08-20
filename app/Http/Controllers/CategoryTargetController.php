<?php

namespace App\Http\Controllers;

use App\Actions\Transactions\SaveCategoryTargets;
use App\Http\Requests\Transactions\CategoryTargetRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class CategoryTargetController extends Controller
{
    /**
     * Save one month's targets. The month comes from the payload rather than
     * the query string, so the write always lands on the month the form was
     * opened for.
     */
    public function store(CategoryTargetRequest $request, SaveCategoryTargets $save): RedirectResponse
    {
        $save->handle(
            $request->user()->id,
            $request->validated('month'),
            $request->targetsInMinor(),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Targets saved.')]);

        return back();
    }
}
