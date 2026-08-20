<?php

use App\Actions\Monzo\SyncMonzoConnection;
use App\Models\Account;
use App\Models\BankConnection;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->account = Account::factory()->monzo()->for($this->user)->create();
});

test('editing a field records it as an override', function () {
    $transaction = Transaction::factory()->forAccount($this->account)->create([
        'name' => 'TESCO STORES',
        'category' => 'trips',
    ]);

    $this->actingAs($this->user)
        ->from(route('transactions.index'))
        ->patch(route('transactions.update', $transaction), [
            'name' => 'Weekly shop',
            'category' => 'groceries',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('transactions.index'));

    $transaction->refresh();

    expect($transaction->name)->toBe('Weekly shop');
    expect($transaction->category)->toBe('groceries');
    expect($transaction->isOverridden('name'))->toBeTrue();
    expect($transaction->isOverridden('notes'))->toBeFalse();

    /**
     * The category is not an override. No import writes one, so there is
     * nothing for the map to protect; categorised_by is what stops a rule
     * refiling the row.
     */
    expect($transaction->isOverridden('category'))->toBeFalse();
    expect($transaction->categorised_by)->toBe('user');
});

/** The dialog closes on the redirect, so the toast is what reports the save. */
test('a successful edit flashes a success message', function () {
    $transaction = Transaction::factory()->forAccount($this->account)->create();

    $this->actingAs($this->user)
        ->from(route('transactions.index'))
        ->patch(route('transactions.update', $transaction), ['name' => 'Weekly shop'])
        ->assertRedirect(route('transactions.index'))
        ->assertInertiaFlash('toast.type', 'success')
        ->assertInertiaFlash('toast.message', 'Transaction updated.');
});

test('a rejected edit flashes nothing', function () {
    $transaction = Transaction::factory()->forAccount($this->account)->create();

    $this->actingAs($this->user)
        ->from(route('transactions.index'))
        ->patch(route('transactions.update', $transaction), ['category' => 'not_a_category'])
        ->assertSessionHasErrors('category')
        ->assertInertiaFlashMissing('toast');
});

test('a hand edit survives a later sync', function () {
    Http::preventStrayRequests();

    $connection = BankConnection::factory()->for($this->user)->create();

    /** One Monzo account per user, so the one from the setup is reused. */
    $this->account->forceFill([
        'bank_connection_id' => $connection->id,
        'external_id' => 'acc_1',
    ])->save();

    $payload = [
        'id' => 'tx_1',
        'created' => '2026-03-01T12:00:00Z',
        'description' => 'TESCO STORES 3297',
        'amount' => -1250,
        'currency' => 'GBP',
        'category' => 'general',
        'merchant' => ['id' => 'm_1', 'name' => 'Tesco'],
    ];

    Http::fake([
        'api.monzo.com/accounts*' => Http::response(['accounts' => [
            ['id' => 'acc_1', 'description' => 'Current account', 'type' => 'uk_retail'],
        ]]),
        'api.monzo.com/transactions*' => Http::response(['transactions' => [$payload]]),
    ]);

    app(SyncMonzoConnection::class)->handle($connection, initial: true);

    $transaction = Transaction::first();

    $this->actingAs($this->user)
        ->from(route('transactions.index'))
        ->patch(route('transactions.update', $transaction), [
            'name' => 'Weekly shop',
            'category' => 'groceries',
            'notes' => 'split with Sam',
        ]);

    app(SyncMonzoConnection::class)->handle($connection, initial: true);

    $transaction->refresh();

    expect($transaction->name)->toBe('Weekly shop');
    expect($transaction->category)->toBe('groceries');
    expect($transaction->notes)->toBe('split with Sam');

    /** Untouched bank fields still track the bank. */
    expect($transaction->merchant_name)->toBe('Tesco');
    expect($transaction->amount_minor)->toBe(-1250);
    expect(Transaction::count())->toBe(1);
});

test('a synced row starts unprocessed and stays marked off once the user marks it', function () {
    Http::preventStrayRequests();

    $connection = BankConnection::factory()->for($this->user)->create();

    $this->account->forceFill([
        'bank_connection_id' => $connection->id,
        'external_id' => 'acc_1',
    ])->save();

    $payload = [
        'id' => 'tx_1',
        'created' => '2026-03-01T12:00:00Z',
        'description' => 'TESCO STORES 3297',
        'amount' => -1250,
        'currency' => 'GBP',
        'category' => 'general',
        'merchant' => ['id' => 'm_1', 'name' => 'Tesco'],
    ];

    Http::fake([
        'api.monzo.com/accounts*' => Http::response(['accounts' => [
            ['id' => 'acc_1', 'description' => 'Current account', 'type' => 'uk_retail'],
        ]]),
        'api.monzo.com/transactions*' => Http::response(['transactions' => [$payload]]),
    ]);

    app(SyncMonzoConnection::class)->handle($connection, initial: true);

    $transaction = Transaction::first();

    /** An imported row arrives unread. */
    expect($transaction->processed)->toBeFalse();

    $this->actingAs($this->user)
        ->from(route('transactions.index'))
        ->patch(route('transactions.update', $transaction), ['processed' => true]);

    app(SyncMonzoConnection::class)->handle($connection, initial: true);

    expect($transaction->fresh()->processed)->toBeTrue();
});

/**
 * Tags have their own field in the edit dialog, so a hand edit never re-reads
 * the note. Lifting "#word" out of a note is the bank's convention and belongs
 * to the import, not to editing.
 */
test('editing notes leaves the tags alone', function () {
    $transaction = Transaction::factory()->forAccount($this->account)->create([
        'tags' => ['shared'],
    ]);

    $this->actingAs($this->user)
        ->from(route('transactions.index'))
        ->patch(route('transactions.update', $transaction), [
            'notes' => 'Client dinner #work #billable',
        ]);

    $transaction->refresh();

    expect($transaction->notes)->toBe('Client dinner #work #billable');
    expect($transaction->tags)->toBe(['shared']);
    expect($transaction->isOverridden('tags'))->toBeFalse();
});

test('tags are edited on their own and recorded as an override', function () {
    $transaction = Transaction::factory()->forAccount($this->account)->create([
        'tags' => ['shared'],
    ]);

    $this->actingAs($this->user)
        ->from(route('transactions.index'))
        ->patch(route('transactions.update', $transaction), [
            'tags' => ['work', 'billable'],
        ])
        ->assertSessionHasNoErrors();

    $transaction->refresh();

    expect($transaction->tags)->toBe(['work', 'billable']);
    expect($transaction->isOverridden('tags'))->toBeTrue();
    expect($transaction->isOverridden('notes'))->toBeFalse();
});

test('a tag is stored the one way however it was typed', function () {
    $transaction = Transaction::factory()->forAccount($this->account)->create();

    $this->actingAs($this->user)
        ->from(route('transactions.index'))
        ->patch(route('transactions.update', $transaction), [
            'tags' => ['#Work', ' client dinner ', 'work', ''],
        ])
        ->assertSessionHasNoErrors();

    expect($transaction->fresh()->tags)->toBe(['work', 'client-dinner']);
});

/**
 * Null rather than [], so a row cleared of its tags reads the same as a row
 * that never had any. The facets query looks for a null.
 */
test('clearing every tag stores null', function () {
    $transaction = Transaction::factory()->forAccount($this->account)->create([
        'tags' => ['work'],
    ]);

    $this->actingAs($this->user)
        ->from(route('transactions.index'))
        ->patch(route('transactions.update', $transaction), ['tags' => []])
        ->assertSessionHasNoErrors();

    expect($transaction->fresh()->tags)->toBeNull();
});

test('a hand edited tag survives a later sync', function () {
    Http::preventStrayRequests();

    $connection = BankConnection::factory()->for($this->user)->create();

    $this->account->forceFill([
        'bank_connection_id' => $connection->id,
        'external_id' => 'acc_1',
    ])->save();

    $payload = [
        'id' => 'tx_1',
        'created' => '2026-03-01T12:00:00Z',
        'description' => 'DISHOOM',
        'notes' => 'dinner #personal',
        'amount' => -4200,
        'currency' => 'GBP',
    ];

    Http::fake([
        'api.monzo.com/accounts*' => Http::response(['accounts' => [
            ['id' => 'acc_1', 'description' => 'Current account', 'type' => 'uk_retail'],
        ]]),
        'api.monzo.com/transactions*' => Http::response(['transactions' => [$payload]]),
    ]);

    app(SyncMonzoConnection::class)->handle($connection, initial: true);

    $transaction = Transaction::first();

    expect($transaction->tags)->toBe(['personal']);

    $this->actingAs($this->user)
        ->from(route('transactions.index'))
        ->patch(route('transactions.update', $transaction), ['tags' => ['work', 'billable']]);

    app(SyncMonzoConnection::class)->handle($connection, initial: true);

    $transaction->refresh();

    expect($transaction->tags)->toBe(['work', 'billable']);
    /** The note itself is still the bank's. */
    expect($transaction->notes)->toBe('dinner #personal');
});

test('a tag that is too long is rejected', function () {
    $transaction = Transaction::factory()->forAccount($this->account)->create();

    $this->actingAs($this->user)
        ->from(route('transactions.index'))
        ->patch(route('transactions.update', $transaction), [
            'tags' => [str_repeat('a', 51)],
        ])
        ->assertSessionHasErrors('tags.0');
});

test('the amount can be corrected by hand', function () {
    $transaction = Transaction::factory()->forAccount($this->account)->create(['amount_minor' => -1000]);

    $this->actingAs($this->user)
        ->from(route('transactions.index'))
        ->patch(route('transactions.update', $transaction), ['amount_minor' => -1234]);

    $transaction->refresh();

    expect($transaction->amount_minor)->toBe(-1234);
    expect($transaction->money_out_minor)->toBe(1234);
    expect($transaction->isOverridden('amount_minor'))->toBeTrue();
});

test('an accounting date can be set by hand', function () {
    $transaction = Transaction::factory()->forAccount($this->account)->create([
        'booked_at' => '2026-06-20 19:00:00',
    ]);

    $this->actingAs($this->user)
        ->from(route('transactions.index'))
        ->patch(route('transactions.update', $transaction), ['accounting_date' => '2026-05-10'])
        ->assertSessionHasNoErrors();

    expect($transaction->fresh()->accounting_date->toDateString())->toBe('2026-05-10');
});

test('an accounting date can be cleared', function () {
    $transaction = Transaction::factory()->forAccount($this->account)->create([
        'accounting_date' => '2026-05-10',
    ]);

    $this->actingAs($this->user)
        ->from(route('transactions.index'))
        ->patch(route('transactions.update', $transaction), ['accounting_date' => null])
        ->assertSessionHasNoErrors();

    expect($transaction->fresh()->accounting_date)->toBeNull();
});

/**
 * The accounting date is local state no import writes, so there is nothing for
 * an override to protect it from.
 */
test('an accounting date is not recorded as an override', function () {
    $transaction = Transaction::factory()->forAccount($this->account)->create();

    $this->actingAs($this->user)
        ->from(route('transactions.index'))
        ->patch(route('transactions.update', $transaction), ['accounting_date' => '2026-05-10']);

    $transaction->refresh();

    expect($transaction->isOverridden('accounting_date'))->toBeFalse();
    expect($transaction->overrides)->toBeNull();
});

test('editing a bank field alongside an accounting date records only the bank field', function () {
    $transaction = Transaction::factory()->forAccount($this->account)->create();

    $this->actingAs($this->user)
        ->from(route('transactions.index'))
        ->patch(route('transactions.update', $transaction), [
            'name' => 'Dinner with Sam',
            'accounting_date' => '2026-05-10',
        ]);

    $transaction->refresh();

    expect($transaction->isOverridden('name'))->toBeTrue();
    expect($transaction->isOverridden('accounting_date'))->toBeFalse();
});

/**
 * A flight booked in July for a holiday in August belongs to August, so an
 * accounting date is allowed to run ahead of the current month. The forward
 * month arrow follows it out.
 */
test('an accounting date after the current month is allowed', function () {
    $transaction = Transaction::factory()->forAccount($this->account)->create();
    $future = now()->addMonths(2)->startOfMonth()->addDays(4);

    $this->actingAs($this->user)
        ->from(route('transactions.index'))
        ->patch(route('transactions.update', $transaction), [
            'accounting_date' => $future->toDateString(),
        ])
        ->assertSessionHasNoErrors();

    expect($transaction->fresh()->accounting_date->toDateString())
        ->toBe($future->toDateString());
});

test('a hand set accounting date survives a later sync', function () {
    Http::preventStrayRequests();

    $connection = BankConnection::factory()->for($this->user)->create();

    $this->account->forceFill([
        'bank_connection_id' => $connection->id,
        'external_id' => 'acc_1',
    ])->save();

    Http::fake([
        'api.monzo.com/accounts*' => Http::response(['accounts' => [
            ['id' => 'acc_1', 'description' => 'Current account', 'type' => 'uk_retail'],
        ]]),
        'api.monzo.com/transactions*' => Http::response(['transactions' => [[
            'id' => 'tx_1',
            'created' => '2026-03-01T12:00:00Z',
            'description' => 'DINNER',
            'amount' => -4500,
            'currency' => 'GBP',
            'category' => 'eating_out',
        ]]]),
    ]);

    app(SyncMonzoConnection::class)->handle($connection, initial: true);

    $transaction = Transaction::first();

    $this->actingAs($this->user)
        ->from(route('transactions.index'))
        ->patch(route('transactions.update', $transaction), ['accounting_date' => '2026-02-20']);

    app(SyncMonzoConnection::class)->handle($connection, initial: true);

    expect($transaction->fresh()->accounting_date->toDateString())->toBe('2026-02-20');
});

test('marking a transaction processed is not recorded as an override', function () {
    $transaction = Transaction::factory()->forAccount($this->account)->create();

    expect($transaction->processed)->toBeFalse();

    $this->actingAs($this->user)
        ->from(route('transactions.index'))
        ->patch(route('transactions.update', $transaction), ['processed' => true])
        ->assertSessionHasNoErrors();

    $transaction->refresh();

    expect($transaction->processed)->toBeTrue();

    /**
     * `processed` is the user's own bookkeeping rather than a value the bank
     * owns, so there is nothing for a sync to undo and nothing to protect.
     */
    expect($transaction->overrides)->toBeNull();
});

test('a processed transaction can be marked unprocessed again', function () {
    $transaction = Transaction::factory()->forAccount($this->account)->processed()->create();

    $this->actingAs($this->user)
        ->from(route('transactions.index'))
        ->patch(route('transactions.update', $transaction), ['processed' => false]);

    expect($transaction->fresh()->processed)->toBeFalse();
});

/** Editing anything else must not quietly mark the row off, or vice versa. */
test('an edit that says nothing about processed leaves it alone', function () {
    $transaction = Transaction::factory()->forAccount($this->account)->processed()->create();

    $this->actingAs($this->user)
        ->from(route('transactions.index'))
        ->patch(route('transactions.update', $transaction), ['name' => 'Weekly shop']);

    $transaction->refresh();

    expect($transaction->name)->toBe('Weekly shop');
    expect($transaction->processed)->toBeTrue();
});

test('an invalid category is rejected', function () {
    $transaction = Transaction::factory()->forAccount($this->account)->create();

    $this->actingAs($this->user)
        ->from(route('transactions.index'))
        ->patch(route('transactions.update', $transaction), ['category' => 'not_a_category'])
        ->assertSessionHasErrors('category');
});

test('you cannot edit another user transaction', function () {
    $other = User::factory()->create();
    $otherAccount = Account::factory()->monzo()->for($other)->create();
    $transaction = Transaction::factory()->forAccount($otherAccount)->create(['name' => 'Theirs']);

    $this->actingAs($this->user)
        ->patch(route('transactions.update', $transaction), ['name' => 'Mine now'])
        ->assertForbidden();

    expect($transaction->fresh()->name)->toBe('Theirs');
});
