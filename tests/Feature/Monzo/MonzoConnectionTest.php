<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Jobs\SyncMonzoConnectionJob;
use App\Models\Account;
use App\Models\BankConnection;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('services.monzo.client_id', 'oauth2client_test');
    config()->set('services.monzo.client_secret', 'secret_test');
    config()->set('services.monzo.redirect', 'http://localhost/settings/connections/monzo/callback');

    Http::preventStrayRequests();

    $this->user = User::factory()->create();
});

function fakeSuccessfulExchange(array $transactions = []): void
{
    Http::fake([
        'api.monzo.com/oauth2/token' => Http::response([
            'access_token' => 'access-123',
            'refresh_token' => 'refresh-456',
            'expires_in' => 21600,
            'user_id' => 'user_abc',
        ]),
        'api.monzo.com/accounts*' => Http::response(['accounts' => [
            ['id' => 'acc_1', 'description' => 'Current account', 'type' => 'uk_retail'],
        ]]),
        'api.monzo.com/transactions*' => Http::response(['transactions' => $transactions]),
    ]);
}

test('the connections page is only reachable when signed in', function () {
    $this->get(route('connections.edit'))->assertRedirect(route('login'));
});

test('the connections page renders', function () {
    $this->actingAs($this->user)->get(route('connections.edit'))->assertOk();
});

test('starting a connection stores a state and redirects to monzo', function () {
    $response = $this->actingAs($this->user)->get(route('monzo.connect'));

    $connection = $this->user->fresh()->monzoConnection;

    expect($connection)->not->toBeNull();
    expect($connection->oauth_state)->not->toBeNull();

    $response->assertRedirectContains('https://auth.monzo.com/');
    $response->assertRedirectContains('client_id=oauth2client_test');
    $response->assertRedirectContains('state='.$connection->oauth_state);
});

test('an inertia visit gets a location header rather than a redirect it cannot follow', function () {
    /**
     * Following a plain redirect to auth.monzo.com over XHR is blocked by the
     * browser's cross origin rules, so an Inertia visit must be answered with
     * a 409 telling the client to navigate instead.
     */
    $response = $this->actingAs($this->user)
        ->withHeader('X-Inertia', 'true')
        /** The real asset version, or Inertia answers with a reload instead. */
        ->withHeader('X-Inertia-Version', (string) app(HandleInertiaRequests::class)
            ->version(Request::create(route('monzo.connect'))))
        ->get(route('monzo.connect'));

    $response->assertStatus(409);
    expect($response->headers->get('X-Inertia-Location'))->toStartWith('https://auth.monzo.com/');
    expect($response->headers->get('X-Inertia-Location'))->toContain('client_id=oauth2client_test');
});

test('a direct browser request still gets an ordinary redirect', function () {
    $this->actingAs($this->user)
        ->get(route('monzo.connect'))
        ->assertStatus(302)
        ->assertRedirectContains('https://auth.monzo.com/');
});

test('starting a connection without credentials configured explains itself', function () {
    config()->set('services.monzo.client_id', '');

    $this->actingAs($this->user)
        ->get(route('monzo.connect'))
        ->assertRedirect(route('connections.edit'));

    expect(BankConnection::count())->toBe(0);
});

test('the callback exchanges the code and stores the tokens encrypted', function () {
    fakeSuccessfulExchange();

    $connection = BankConnection::factory()->for($this->user)->pendingAuthorisation()->create();

    $this->actingAs($this->user)
        ->get(route('monzo.callback', ['code' => 'auth-code', 'state' => $connection->oauth_state]))
        ->assertRedirect(route('connections.edit'));

    $connection->refresh();

    expect($connection->access_token)->toBe('access-123');
    expect($connection->refresh_token)->toBe('refresh-456');
    expect($connection->external_user_id)->toBe('user_abc');
    expect($connection->authorised_at)->not->toBeNull();
    expect($connection->oauth_state)->toBeNull();

    $stored = DB::table('bank_connections')->where('id', $connection->id)->value('access_token');
    expect($stored)->not->toBe('access-123');
});

test('the callback pulls the window immediately rather than queueing it', function () {
    Queue::fake();

    fakeSuccessfulExchange([[
        'id' => 'tx_1',
        'created' => now()->subDays(40)->toIso8601ZuluString(),
        'description' => 'OLD PURCHASE',
        'amount' => -4500,
        'currency' => 'GBP',
        'category' => 'shopping',
        'merchant' => ['id' => 'merch_1', 'name' => 'Old Shop'],
    ]]);

    $connection = BankConnection::factory()->for($this->user)->pendingAuthorisation()->create();

    $this->actingAs($this->user)
        ->get(route('monzo.callback', ['code' => 'auth-code', 'state' => $connection->oauth_state]));

    /**
     * Connecting must leave the user with data even when no queue worker is
     * running, so this work happens inline.
     */
    expect(Transaction::count())->toBe(1);

    Queue::assertNothingPushed();

    /** Bounded to the window the app keeps, never open ended. */
    Http::assertSent(function ($request): bool {
        if (! str_contains($request->url(), '/transactions')) {
            return true;
        }

        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return isset($query['since']);
    });
});

test('a mismatched state is rejected', function () {
    $connection = BankConnection::factory()->for($this->user)->pendingAuthorisation()->create();

    $this->actingAs($this->user)
        ->get(route('monzo.callback', ['code' => 'auth-code', 'state' => 'not-the-stored-state']))
        ->assertRedirect(route('connections.edit'));

    expect($connection->fresh()->access_token)->toBeNull();
});

test('a callback with no stored state is rejected', function () {
    BankConnection::factory()->for($this->user)->create(['oauth_state' => null]);

    $this->actingAs($this->user)
        ->get(route('monzo.callback', ['code' => 'auth-code', 'state' => 'anything']))
        ->assertRedirect(route('connections.edit'));
});

test('a denied authorisation is reported and clears the pending state', function () {
    $connection = BankConnection::factory()->for($this->user)->pendingAuthorisation()->create();

    $this->actingAs($this->user)
        ->get(route('monzo.callback', ['error' => 'access_denied', 'state' => $connection->oauth_state]))
        ->assertRedirect(route('connections.edit'));

    $connection->refresh();
    expect($connection->access_token)->toBeNull();
    expect($connection->oauth_state)->toBeNull();
});

test('pending approval keeps the connection and invites a retry', function () {
    Http::fake([
        'api.monzo.com/oauth2/token' => Http::response([
            'access_token' => 'access-123',
            'refresh_token' => 'refresh-456',
            'expires_in' => 21600,
        ]),
        'api.monzo.com/accounts*' => Http::response(['code' => 'forbidden.verification_required'], 403),
    ]);

    $connection = BankConnection::factory()->for($this->user)->pendingAuthorisation()->create();

    $this->actingAs($this->user)
        ->get(route('monzo.callback', ['code' => 'auth-code', 'state' => $connection->oauth_state]))
        ->assertRedirect(route('connections.edit'));

    $connection->refresh();

    /** The tokens are kept so the retry has something to work with. */
    expect($connection->access_token)->toBe('access-123');
    expect($connection->sca_confirmed_at)->toBeNull();
    expect($connection->history_backfilled_at)->toBeNull();
});

test('retrying after approval pulls the window', function () {
    fakeSuccessfulExchange([[
        'id' => 'tx_1',
        'created' => now()->subDays(40)->toIso8601ZuluString(),
        'description' => 'OLD',
        'amount' => -100,
        'currency' => 'GBP',
    ]]);

    $connection = BankConnection::factory()->for($this->user)->pendingSca()->create();

    $this->actingAs($this->user)
        ->post(route('monzo.retry'))
        ->assertRedirect(route('connections.edit'));

    $connection->refresh();
    expect($connection->sca_confirmed_at)->not->toBeNull();
    expect(Transaction::count())->toBe(1);
});

test('disconnecting clears the tokens but keeps the transactions', function () {
    $connection = BankConnection::factory()->for($this->user)->create();
    $account = Account::factory()->monzo()->for($this->user)->create([
        'bank_connection_id' => $connection->id,
    ]);
    Transaction::factory()->forAccount($account)->count(3)->create();

    $this->actingAs($this->user)
        ->delete(route('monzo.disconnect'))
        ->assertRedirect(route('connections.edit'));

    $connection->refresh();
    expect($connection->access_token)->toBeNull();
    expect($connection->refresh_token)->toBeNull();
    expect($connection->revoked_at)->not->toBeNull();
    expect($connection->isActive())->toBeFalse();
    expect(Transaction::count())->toBe(3);
});

test('import monzo queues a background sync and returns to the page it was pressed on', function () {
    Queue::fake();

    BankConnection::factory()->for($this->user)->create();

    /** The button lives on the transactions page, so it must come back there. */
    $this->actingAs($this->user)
        ->from(route('transactions.index'))
        ->post(route('monzo.sync'))
        ->assertRedirect(route('transactions.index'));

    Queue::assertPushed(SyncMonzoConnectionJob::class);
});

test('import monzo refuses when there is no usable connection', function () {
    Queue::fake();

    BankConnection::factory()->for($this->user)->pendingSca()->create();

    $this->actingAs($this->user)
        ->from(route('transactions.index'))
        ->post(route('monzo.sync'));

    Queue::assertNothingPushed();
});

test('the transactions page only offers the import monzo button when a connection is usable', function () {
    /**
     * Each step re-reads the user, because actingAs keeps handing back the
     * same instance and its already loaded relation would go stale.
     */
    $connected = fn (): bool => $this->actingAs($this->user->fresh())
        ->get(route('transactions.index'))
        ->viewData('page')['props']['monzoConnected'];

    expect($connected())->toBeFalse();

    $connection = BankConnection::factory()->for($this->user)->pendingSca()->create();

    expect($connected())->toBeFalse();

    /** Approving in the Monzo app is what makes the connection usable. */
    $connection->forceFill(['sca_confirmed_at' => now()])->save();

    expect($connected())->toBeTrue();
});

test('one user cannot see or disturb another user connection', function () {
    $other = User::factory()->create();
    $connection = BankConnection::factory()->for($other)->create();

    $this->actingAs($this->user)->delete(route('monzo.disconnect'));

    expect($connection->fresh()->revoked_at)->toBeNull();
});
