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
        'category' => 'general',
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
    expect($transaction->isOverridden('category'))->toBeTrue();
    expect($transaction->isOverridden('notes'))->toBeFalse();
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
            'notes' => 'split with Sam #shared',
        ]);

    app(SyncMonzoConnection::class)->handle($connection, initial: true);

    $transaction->refresh();

    expect($transaction->name)->toBe('Weekly shop');
    expect($transaction->category)->toBe('groceries');
    expect($transaction->notes)->toBe('split with Sam #shared');
    expect($transaction->tags)->toBe(['shared']);

    /** Untouched bank fields still track the bank. */
    expect($transaction->merchant_name)->toBe('Tesco');
    expect($transaction->amount_minor)->toBe(-1250);
    expect(Transaction::count())->toBe(1);
});

test('editing notes re-derives the tags', function () {
    $transaction = Transaction::factory()->forAccount($this->account)->create(['tags' => null]);

    $this->actingAs($this->user)
        ->from(route('transactions.index'))
        ->patch(route('transactions.update', $transaction), [
            'notes' => 'Client dinner #work #billable',
        ]);

    expect($transaction->fresh()->tags)->toBe(['work', 'billable']);
});

test('tags sent explicitly win over tags parsed from notes', function () {
    $transaction = Transaction::factory()->forAccount($this->account)->create();

    $this->actingAs($this->user)
        ->from(route('transactions.index'))
        ->patch(route('transactions.update', $transaction), [
            'notes' => 'Client dinner #work',
            'tags' => ['personal'],
        ]);

    expect($transaction->fresh()->tags)->toBe(['personal']);
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
