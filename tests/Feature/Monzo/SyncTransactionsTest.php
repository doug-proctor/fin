<?php

use App\Actions\Monzo\SyncMonzoConnection;
use App\Actions\Monzo\SyncTransactions;
use App\Exceptions\Monzo\ScaRequiredException;
use App\Models\Account;
use App\Models\BankConnection;
use App\Models\Category;
use App\Models\CategoryRule;
use App\Models\MonzoSyncReport;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();

    $this->user = User::factory()->create();
    $this->connection = BankConnection::factory()->for($this->user)->create();
});

/**
 * The `since` a request asked for, or null when it was not a transactions
 * call and so has no bearing on the assertion.
 */
function sinceOf(Request $request): ?Carbon
{
    if (! str_contains($request->url(), '/transactions')) {
        return null;
    }

    parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

    return isset($query['since']) ? Carbon::parse((string) $query['since']) : null;
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function monzoTransaction(string $id, array $overrides = []): array
{
    return array_merge([
        'id' => $id,
        'created' => '2026-03-01T12:00:00Z',
        'settled' => '2026-03-02T12:00:00Z',
        'description' => 'TESCO STORES 3297 LONDON GBR',
        'amount' => -1250,
        'currency' => 'GBP',
        'local_amount' => -1250,
        'local_currency' => 'GBP',
        'category' => 'groceries',
        'notes' => '',
        'metadata' => [],
        'is_load' => false,
        'include_in_spending' => true,
        'scheme' => 'gps_mastercard',
        'counterparty' => [],
        'merchant' => [
            'id' => 'merch_123',
            'name' => 'Tesco',
            'emoji' => '🛒',
            'category' => 'groceries',
            'address' => ['short_formatted' => '10 High St, London'],
        ],
    ], $overrides);
}

test('a full sync stores accounts and transactions', function () {
    Http::fake([
        'api.monzo.com/accounts*' => Http::response(['accounts' => [
            ['id' => 'acc_1', 'description' => 'Current account', 'type' => 'uk_retail', 'currency' => 'GBP', 'sort_code' => '040004', 'account_number' => '12345678'],
        ]]),
        'api.monzo.com/transactions*' => Http::response(['transactions' => [
            monzoTransaction('tx_1'),
            monzoTransaction('tx_2', ['amount' => -500, 'category' => 'eating_out']),
        ]]),
    ]);

    $summary = app(SyncMonzoConnection::class)->handle($this->connection, initial: true);

    expect($summary->created)->toBe(2);
    expect(Account::count())->toBe(1);
    expect(Transaction::count())->toBe(2);

    $account = Account::first();
    expect($account->external_id)->toBe('acc_1');
    /** Monzo's own description is an internal id, so the name is set here. */
    expect($account->name)->toBe('Monzo');

    $transaction = Transaction::where('external_id', 'tx_1')->first();
    expect($transaction->amount_minor)->toBe(-1250);
    expect($transaction->money_out_minor)->toBe(1250);
    expect($transaction->name)->toBe('Tesco');
    expect($transaction->merchant_name)->toBe('Tesco');
    expect($transaction->type)->toBe('card_payment');

    /** Monzo said 'groceries'; no rule matched, so the row is unfiled. */
    expect($transaction->category)->toBeNull();
    expect($transaction->categorised_by)->toBeNull();
});

test('an initial pull asks from the fixed start date', function () {
    Http::fake([
        'api.monzo.com/accounts*' => Http::response(['accounts' => [['id' => 'acc_1', 'type' => 'uk_retail']]]),
        'api.monzo.com/transactions*' => Http::response(['transactions' => []]),
    ]);

    app(SyncMonzoConnection::class)->handle($this->connection, initial: true);

    /**
     * A fixed date, not a count of days back from today, so the ledger starts
     * on the same day whenever the first pull happens.
     */
    Http::assertSent(fn ($request) => sinceOf($request)?->toDateString()
        === SyncTransactions::INITIAL_START_DATE ?? true);
});

test('an initial pull settles for the recent window when monzo refuses six months', function () {
    /**
     * Six months is only served inside the five minutes after authorising.
     * Afterwards Monzo answers 403, and since the accounts call already
     * succeeded the refusal can only mean the request reached too far back.
     */
    Http::fake([
        'api.monzo.com/accounts*' => Http::response(['accounts' => [['id' => 'acc_1', 'type' => 'uk_retail']]]),
        'api.monzo.com/transactions*' => Http::sequence()
            ->push(['code' => 'forbidden.verification_required'], 403)
            ->push(['transactions' => [monzoTransaction('tx_1')]]),
    ]);

    $summary = app(SyncMonzoConnection::class)->handle($this->connection, initial: true);

    expect($summary->created)->toBe(1);

    Http::assertSent(fn ($request) => sinceOf($request)?->between(
        now()->subDays(91), now()->subDays(87),
    ) ?? true);
});

test('every later pull asks for the recent window, not just what is new', function () {
    Http::fake([
        'api.monzo.com/accounts*' => Http::response(['accounts' => [['id' => 'acc_1', 'type' => 'uk_retail']]]),
        'api.monzo.com/transactions*' => Http::response(['transactions' => []]),
    ]);

    $account = Account::factory()->monzo()->for($this->user)->create([
        'bank_connection_id' => $this->connection->id,
        'external_id' => 'acc_1',
    ]);
    Transaction::factory()->forAccount($account)->create(['booked_at' => now()->subDays(2)]);

    app(SyncMonzoConnection::class)->handle($this->connection);

    /**
     * A stored transaction does not shorten the ask. Starting from the newest
     * row would mean an older one whose category changed at the bank is never
     * looked at again.
     */
    Http::assertSent(fn ($request) => sinceOf($request)?->between(
        now()->subDays(91), now()->subDays(87),
    ) ?? true);
});

test('a pull more than 89 days after the last one records the gap it left', function () {
    Http::fake([
        'api.monzo.com/accounts*' => Http::response(['accounts' => [['id' => 'acc_1', 'type' => 'uk_retail']]]),
        'api.monzo.com/transactions*' => Http::response(['transactions' => []]),
    ]);

    $this->connection->forceFill(['last_synced_at' => now()->subDays(200)])->save();

    app(SyncMonzoConnection::class)->handle($this->connection);

    $report = MonzoSyncReport::where('user_id', $this->user->id)->latest('id')->first();

    expect($report->hasGap())->toBeTrue();
    expect($report->gap_from->isSameDay(now()->subDays(200)))->toBeTrue();
    expect($report->gap_to->isSameDay(now()->subDays(89)))->toBeTrue();
});

test('a pull inside the window records no gap', function () {
    Http::fake([
        'api.monzo.com/accounts*' => Http::response(['accounts' => [['id' => 'acc_1', 'type' => 'uk_retail']]]),
        'api.monzo.com/transactions*' => Http::response(['transactions' => []]),
    ]);

    $this->connection->forceFill(['last_synced_at' => now()->subDays(30)])->save();

    app(SyncMonzoConnection::class)->handle($this->connection);

    expect(MonzoSyncReport::where('user_id', $this->user->id)->latest('id')->first()->hasGap())
        ->toBeFalse();
});

test('the first ever pull records no gap', function () {
    Http::fake([
        'api.monzo.com/accounts*' => Http::response(['accounts' => [['id' => 'acc_1', 'type' => 'uk_retail']]]),
        'api.monzo.com/transactions*' => Http::response(['transactions' => []]),
    ]);

    $this->connection->forceFill(['last_synced_at' => null])->save();

    app(SyncMonzoConnection::class)->handle($this->connection, initial: true);

    expect(MonzoSyncReport::where('user_id', $this->user->id)->latest('id')->first()->hasGap())
        ->toBeFalse();
});

test('re-syncing the same transactions creates no duplicates', function () {
    Http::fake([
        'api.monzo.com/accounts*' => Http::response(['accounts' => [['id' => 'acc_1', 'type' => 'uk_retail']]]),
        'api.monzo.com/transactions*' => Http::response(['transactions' => [monzoTransaction('tx_1')]]),
    ]);

    $sync = app(SyncMonzoConnection::class);

    $first = $sync->handle($this->connection, initial: true);
    $second = $sync->handle($this->connection, initial: true);

    expect($first->created)->toBe(1);
    expect($second->created)->toBe(0);
    expect($second->updated)->toBe(1);
    expect(Transaction::count())->toBe(1);
});

test('a re-sync refreshes bank fields that the user has not touched', function () {
    Http::fake([
        'api.monzo.com/accounts*' => Http::response(['accounts' => [['id' => 'acc_1', 'type' => 'uk_retail']]]),
        'api.monzo.com/transactions*' => Http::sequence()
            ->push(['transactions' => [monzoTransaction('tx_1', ['settled' => ''])]])
            ->push(['transactions' => [monzoTransaction('tx_1', ['settled' => '2026-03-05T09:00:00Z', 'amount' => -1300])]]),
    ]);

    $sync = app(SyncMonzoConnection::class);
    $sync->handle($this->connection, initial: true);

    expect(Transaction::first()->amount_minor)->toBe(-1250);

    $sync->handle($this->connection, initial: true);

    expect(Transaction::first()->amount_minor)->toBe(-1300);
});

test('a re-sync never writes over a field the user has edited by hand', function () {
    Http::fake([
        'api.monzo.com/accounts*' => Http::response(['accounts' => [['id' => 'acc_1', 'type' => 'uk_retail']]]),
        'api.monzo.com/transactions*' => Http::response(['transactions' => [
            monzoTransaction('tx_1', ['category' => 'groceries', 'notes' => 'from the bank']),
        ]]),
    ]);

    $sync = app(SyncMonzoConnection::class);
    $sync->handle($this->connection, initial: true);

    $transaction = Transaction::first();
    $transaction->category = 'personal_care';
    $transaction->notes = 'my own note';
    $transaction->name = 'Renamed by me';
    $transaction->markOverridden(['category', 'notes', 'name']);
    $transaction->categorised_by = 'user';
    $transaction->save();

    $sync->handle($this->connection, initial: true);

    $transaction = Transaction::first();
    expect($transaction->category)->toBe('personal_care');
    expect($transaction->notes)->toBe('my own note');
    expect($transaction->name)->toBe('Renamed by me');

    /** Fields the user did not claim are still refreshed from the bank. */
    expect($transaction->merchant_name)->toBe('Tesco');
});

test('pending strong customer authentication surfaces as its own exception', function () {
    Http::fake(['api.monzo.com/accounts*' => Http::response(['code' => 'forbidden.verification_required'], 403)]);

    $connection = BankConnection::factory()->for(User::factory())->pendingSca()->create();

    expect(fn () => app(SyncMonzoConnection::class)->handle($connection))
        ->toThrow(ScaRequiredException::class);

    expect($connection->fresh()->sca_confirmed_at)->toBeNull();
});

test('a successful accounts call confirms strong customer authentication', function () {
    Http::fake([
        'api.monzo.com/accounts*' => Http::response(['accounts' => [['id' => 'acc_1', 'type' => 'uk_retail']]]),
        'api.monzo.com/transactions*' => Http::response(['transactions' => []]),
    ]);

    $connection = BankConnection::factory()->for(User::factory())->pendingSca()->create();

    app(SyncMonzoConnection::class)->handle($connection, initial: true);

    expect($connection->fresh()->sca_confirmed_at)->not->toBeNull();
});

test('a failing sync records the error against the connection', function () {
    Http::fake(['api.monzo.com/accounts*' => Http::response(['code' => 'internal'], 500)]);

    expect(fn () => app(SyncMonzoConnection::class)->handle($this->connection))->toThrow(Exception::class);

    expect($this->connection->fresh()->last_sync_error)->toContain('500');
});

test('only the current account is mirrored, not the rest of the monzo estate', function () {
    Http::fake([
        'api.monzo.com/accounts*' => Http::response(['accounts' => [
            ['id' => 'acc_closed', 'type' => 'uk_retail', 'closed' => true],
            ['id' => 'acc_rewards', 'type' => 'uk_rewards'],
            ['id' => 'acc_flex', 'type' => 'uk_monzo_flex'],
            ['id' => 'acc_joint', 'type' => 'uk_retail_joint'],
            ['id' => 'acc_1', 'type' => 'uk_retail'],
        ]]),
        'api.monzo.com/transactions*' => Http::response(['transactions' => []]),
    ]);

    app(SyncMonzoConnection::class)->handle($this->connection, initial: true);

    /** This app holds exactly two accounts, so Monzo contributes one. */
    expect(Account::count())->toBe(1);
    expect(Account::first()->external_id)->toBe('acc_1');
    expect(Account::first()->name)->toBe('Monzo');
});

test('syncing repeatedly never adds a second monzo account', function () {
    Http::fake([
        'api.monzo.com/accounts*' => Http::response(['accounts' => [
            ['id' => 'acc_1', 'type' => 'uk_retail'],
        ]]),
        'api.monzo.com/transactions*' => Http::response(['transactions' => []]),
    ]);

    $sync = app(SyncMonzoConnection::class);
    $sync->handle($this->connection, initial: true);
    $sync->handle($this->connection);

    expect(Account::where('provider', 'monzo')->count())->toBe(1);
});

test('an expiring access token is refreshed before the sync runs', function () {
    Http::fake([
        'api.monzo.com/oauth2/token' => Http::response([
            'access_token' => 'refreshed-token',
            'refresh_token' => 'new-refresh',
            'expires_in' => 21600,
        ]),
        'api.monzo.com/accounts*' => Http::response(['accounts' => []]),
    ]);

    $connection = BankConnection::factory()->for(User::factory())->expired()->create();

    app(SyncMonzoConnection::class)->handle($connection);

    expect($connection->fresh()->access_token)->toBe('refreshed-token');
    expect($connection->fresh()->refresh_token)->toBe('new-refresh');
});

test('notes hash tags are parsed into the tags column', function () {
    Http::fake([
        'api.monzo.com/accounts*' => Http::response(['accounts' => [['id' => 'acc_1', 'type' => 'uk_retail']]]),
        'api.monzo.com/transactions*' => Http::response(['transactions' => [
            monzoTransaction('tx_1', ['notes' => 'Client lunch #work #reimbursable']),
        ]]),
    ]);

    app(SyncMonzoConnection::class)->handle($this->connection, initial: true);

    expect(Transaction::first()->tags)->toBe(['work', 'reimbursable']);
});

test('monzo categories are ignored entirely, and only a rule can file a row', function () {
    CategoryRule::factory()->for($this->user)->create([
        'match_field' => 'any',
        'match_type' => 'contains',
        'match_values' => ['TESCO'],
        'set_category' => 'groceries',
        'is_active' => true,
    ]);

    Http::fake([
        'api.monzo.com/accounts*' => Http::response(['accounts' => [['id' => 'acc_1', 'type' => 'uk_retail']]]),
        'api.monzo.com/transactions*' => Http::response(['transactions' => [
            /** Monzo sends one of its own values and one of its opaque ids. */
            monzoTransaction('tx_1', ['category' => 'category_0000B86WnKknuzF8vd1v9g']),
            monzoTransaction('tx_2', ['category' => 'eating_out', 'description' => 'PRET A MANGER LONDON GBR', 'merchant' => []]),
        ]]),
    ]);

    app(SyncMonzoConnection::class)->handle($this->connection, initial: true);

    /** The rule matched on the description, not on anything Monzo sent. */
    $matched = Transaction::where('external_id', 'tx_1')->first();
    expect($matched->category)->toBe('groceries');
    expect($matched->categorised_by)->toBe('rule');

    /** No rule reached this one, so Monzo's 'eating_out' buys it nothing. */
    $unmatched = Transaction::where('external_id', 'tx_2')->first();
    expect($unmatched->category)->toBeNull();
    expect($unmatched->categorised_by)->toBeNull();

    /** Nothing Monzo sent was registered as a category of the user's. */
    expect(Category::where('user_id', $this->user->id)->pluck('value')
        ->filter(fn (string $value): bool => str_starts_with($value, 'category_')))
        ->toBeEmpty();
});

test('a declined transaction is never imported', function () {
    Http::fake([
        'api.monzo.com/accounts*' => Http::response(['accounts' => [['id' => 'acc_1', 'type' => 'uk_retail']]]),
        'api.monzo.com/transactions*' => Http::response(['transactions' => [
            monzoTransaction('tx_1', ['decline_reason' => 'INSUFFICIENT_FUNDS']),
            monzoTransaction('tx_2'),
        ]]),
    ]);

    app(SyncMonzoConnection::class)->handle($this->connection, initial: true);

    /** No money moved, so the row would only ever be noise in the table. */
    expect(Transaction::count())->toBe(1);
    expect(Transaction::first()->external_id)->toBe('tx_2');
});

test('a refused transactions endpoint surfaces rather than being swallowed', function () {
    Http::fake([
        'api.monzo.com/accounts*' => Http::response(['accounts' => [['id' => 'acc_1', 'type' => 'uk_retail']]]),
        'api.monzo.com/transactions*' => Http::response(['code' => 'forbidden.verification_required'], 403),
    ]);

    $connection = BankConnection::factory()->for(User::factory())->create();

    expect(fn () => app(SyncMonzoConnection::class)->handle($connection, initial: true))
        ->toThrow(ScaRequiredException::class);
});

test('a sync writes a report of what it brought in', function () {
    Http::fake([
        'api.monzo.com/accounts*' => Http::response(['accounts' => [['id' => 'acc_1', 'type' => 'uk_retail']]]),
        'api.monzo.com/transactions*' => Http::response(['transactions' => [
            monzoTransaction('tx_1', ['created' => now()->subDays(9)->toIso8601ZuluString()]),
            monzoTransaction('tx_2', ['created' => now()->subDays(2)->toIso8601ZuluString()]),
        ]]),
    ]);

    app(SyncMonzoConnection::class)->handle($this->connection, initial: true);

    $report = MonzoSyncReport::where('user_id', $this->user->id)->latest('id')->first();

    expect($report->status)->toBe(MonzoSyncReport::STATUS_COMPLETED);
    expect($report->imported)->toBe(2);
    expect($report->oldest_booked_at->isSameDay(now()->subDays(9)))->toBeTrue();
    expect($report->newest_booked_at->isSameDay(now()->subDays(2)))->toBeTrue();
    expect($report->finished_at)->not->toBeNull();
});

test('a second sync of the same transactions reports nothing imported', function () {
    Http::fake([
        'api.monzo.com/accounts*' => Http::response(['accounts' => [['id' => 'acc_1', 'type' => 'uk_retail']]]),
        'api.monzo.com/transactions*' => Http::response(['transactions' => [monzoTransaction('tx_1')]]),
    ]);

    app(SyncMonzoConnection::class)->handle($this->connection, initial: true);
    app(SyncMonzoConnection::class)->handle($this->connection, initial: true);

    $report = MonzoSyncReport::where('user_id', $this->user->id)->latest('id')->first();

    /** A quiet run, which must read as such and not as a failure. */
    expect($report->imported)->toBe(0);
    expect($report->oldest_booked_at)->toBeNull();
    expect($report->newest_booked_at)->toBeNull();
    expect($report->status)->toBe(MonzoSyncReport::STATUS_COMPLETED);
});

test('a failed sync is recorded so a broken connection is not mistaken for a quiet night', function () {
    Http::fake([
        'api.monzo.com/accounts*' => Http::response(['accounts' => [['id' => 'acc_1', 'type' => 'uk_retail']]]),
        'api.monzo.com/transactions*' => Http::response(['code' => 'internal'], 500),
    ]);

    expect(fn () => app(SyncMonzoConnection::class)->handle($this->connection, initial: true))
        ->toThrow(Exception::class);

    $report = MonzoSyncReport::where('user_id', $this->user->id)->latest('id')->first();

    expect($report->status)->toBe(MonzoSyncReport::STATUS_FAILED);
    expect($report->error)->not->toBeNull();
    expect($report->finished_at)->not->toBeNull();
});
