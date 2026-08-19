<?php

namespace App\Http\Controllers\Monzo;

use App\Http\Controllers\Controller;
use App\Jobs\SyncMonzoConnectionJob;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class MonzoSyncController extends Controller
{
    /**
     * Queue a routine sync. The one-shot full history backfill is not handled
     * here, because it has to run inside the connect request.
     */
    public function store(Request $request): RedirectResponse
    {
        $connection = $request->user()->monzoConnection;

        if ($connection === null || ! $connection->isActive()) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => __('Connect your Monzo account before importing.'),
            ]);

            return back();
        }

        SyncMonzoConnectionJob::dispatch($connection);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Import started. New transactions will appear shortly.'),
        ]);

        return back();
    }
}
