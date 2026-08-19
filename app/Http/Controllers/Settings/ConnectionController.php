<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\BankConnection;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ConnectionController extends Controller
{
    /**
     * Show the state of every data source feeding the ledger.
     */
    public function edit(Request $request): Response
    {
        $user = $request->user();
        $connection = $user->monzoConnection;

        return Inertia::render('settings/connections', [
            'configured' => $this->isConfigured(),
            'monzo' => $connection === null ? null : $this->presentConnection($connection),
            'accounts' => Account::query()
                ->where('user_id', $user->id)
                ->withCount('transactions')
                ->orderBy('provider')
                ->orderBy('name')
                ->get()
                ->map(fn (Account $account): array => [
                    'id' => $account->id,
                    'name' => $account->name,
                    'provider' => $account->provider,
                    'transactionsCount' => $account->transactions_count,
                    'lastSyncedAt' => $account->last_synced_at?->toIso8601String(),
                ])
                ->all(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentConnection(BankConnection $connection): array
    {
        return [
            'status' => match (true) {
                $connection->revoked_at !== null => 'disconnected',
                $connection->access_token === null => 'disconnected',
                $connection->sca_confirmed_at === null => 'pending_approval',
                default => 'connected',
            },
            'canRefresh' => $connection->refresh_token !== null,
            'lastSyncedAt' => $connection->last_synced_at?->toIso8601String(),
            'lastSyncError' => $connection->last_sync_error,
        ];
    }

    private function isConfigured(): bool
    {
        $clientId = config('services.monzo.client_id');
        $clientSecret = config('services.monzo.client_secret');

        return is_string($clientId) && $clientId !== ''
            && is_string($clientSecret) && $clientSecret !== '';
    }
}
