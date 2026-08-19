<?php

namespace App\Http\Controllers\Monzo;

use App\Actions\Monzo\SyncMonzoConnection;
use App\Contracts\MonzoClient;
use App\Exceptions\Monzo\ScaRequiredException;
use App\Http\Controllers\Controller;
use App\Models\BankConnection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Throwable;

class MonzoConnectionController extends Controller
{
    public function __construct(
        private readonly MonzoClient $monzo,
        private readonly SyncMonzoConnection $sync,
    ) {}

    /**
     * Begin the OAuth flow.
     *
     * Handing off to Monzo has to be a real top level navigation. A plain
     * redirect would be followed by Inertia's XHR, and the browser then blocks
     * the cross origin request to auth.monzo.com. Inertia::location answers an
     * XHR visit with a 409 and a location header the client navigates to, and
     * still returns an ordinary redirect for a direct request.
     */
    public function create(Request $request): SymfonyResponse
    {
        if (! $this->isConfigured()) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => __('Monzo credentials are not configured. Set MONZO_CLIENT_ID and MONZO_CLIENT_SECRET.'),
            ]);

            return to_route('connections.edit');
        }

        $state = Str::random(40);

        BankConnection::query()->updateOrCreate(
            ['user_id' => $request->user()->id, 'provider' => 'monzo'],
            ['oauth_state' => $state, 'revoked_at' => null],
        );

        return Inertia::location($this->monzo->authorizationUrl($state));
    }

    /**
     * Handle the redirect back from Monzo.
     *
     * The full history backfill runs here, synchronously and before anything
     * else, because Monzo only serves transactions older than 90 days for
     * roughly five minutes after authenticating. Handing that work to the
     * queue would risk no worker being running, and the window does not
     * reopen.
     */
    public function store(Request $request): RedirectResponse
    {
        $connection = BankConnection::query()
            ->where('user_id', $request->user()->id)
            ->where('provider', 'monzo')
            ->first();

        if ($connection === null) {
            return $this->fail(__('Start the connection from this page before approving it.'));
        }

        if (is_string($request->query('error'))) {
            $connection->forceFill(['oauth_state' => null])->save();

            return $this->fail(__('Monzo declined the connection request.'));
        }

        $state = $request->query('state');
        $code = $request->query('code');

        /**
         * A mismatched state means the callback did not originate from the
         * redirect this session started.
         */
        if (! is_string($state) || $connection->oauth_state === null || ! hash_equals($connection->oauth_state, $state)) {
            return $this->fail(__('That Monzo response could not be verified. Please try connecting again.'));
        }

        if (! is_string($code) || $code === '') {
            return $this->fail(__('Monzo did not return an authorisation code.'));
        }

        try {
            $tokens = $this->monzo->exchangeCode($code);
        } catch (Throwable $exception) {
            Log::error('Monzo code exchange failed.', ['exception' => $exception->getMessage()]);

            return $this->fail(__('Could not complete the Monzo connection. Please try again.'));
        }

        $connection->forceFill([
            'access_token' => $tokens->accessToken,
            'refresh_token' => $tokens->refreshToken,
            'expires_at' => $tokens->expiresAt,
            'external_user_id' => $tokens->userId,
            'oauth_state' => null,
            'authorised_at' => Carbon::now(),
            'revoked_at' => null,
            'last_sync_error' => null,
        ])->save();

        if (! $tokens->isRefreshable()) {
            Log::warning('Monzo issued no refresh token; the client is probably not registered as confidential.', [
                'connection_id' => $connection->id,
            ]);
        }

        return $this->backfill($connection);
    }

    /**
     * Retry the backfill once the user has approved the push notification.
     */
    public function update(Request $request): RedirectResponse
    {
        $connection = $request->user()->monzoConnection;

        if ($connection === null || $connection->access_token === null) {
            return $this->fail(__('Connect your Monzo account first.'));
        }

        return $this->backfill($connection);
    }

    public function destroy(Request $request): RedirectResponse
    {
        $connection = $request->user()->monzoConnection;

        $connection?->forceFill([
            'access_token' => null,
            'refresh_token' => null,
            'expires_at' => null,
            'oauth_state' => null,
            'sca_confirmed_at' => null,
            'revoked_at' => Carbon::now(),
        ])->save();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Monzo disconnected. Your transactions have been kept.'),
        ]);

        return to_route('connections.edit');
    }

    /**
     * Pull the whole 90 day window on connecting, translating the
     * pending-approval case into a prompt rather than an error.
     */
    private function backfill(BankConnection $connection): RedirectResponse
    {
        try {
            $summary = $this->sync->handle($connection, initial: true);
        } catch (ScaRequiredException) {
            Inertia::flash('toast', [
                'type' => 'info',
                'message' => __('Approve access in your Monzo app, then choose Retry.'),
            ]);

            return to_route('connections.edit');
        } catch (Throwable $exception) {
            Log::error('Monzo backfill failed.', [
                'connection_id' => $connection->id,
                'exception' => $exception->getMessage(),
            ]);

            Inertia::flash('toast', [
                'type' => 'error',
                'message' => __('Connected, but the import failed. Try syncing again from this page.'),
            ]);

            return to_route('connections.edit');
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => trans_choice(
                '{0}Monzo connected. No transactions to import yet.|[1,*]Monzo connected. :count transactions imported.',
                $summary->created,
                ['count' => $summary->created],
            ),
        ]);

        return to_route('connections.edit');
    }

    private function fail(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'error', 'message' => $message]);

        return to_route('connections.edit');
    }

    private function isConfigured(): bool
    {
        return is_string(config('services.monzo.client_id')) && config('services.monzo.client_id') !== ''
            && is_string(config('services.monzo.client_secret')) && config('services.monzo.client_secret') !== '';
    }
}
