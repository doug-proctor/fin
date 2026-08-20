<?php

use App\Models\Account;
use App\Models\AmexSyncReport;
use App\Models\BankConnection;
use App\Models\CategoryRule;
use App\Models\CategoryTarget;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;

test('a monzo connection stores its tokens encrypted at rest', function () {
    $connection = BankConnection::factory()->create(['access_token' => 'secret-token']);

    $raw = DB::table('bank_connections')->where('id', $connection->id)->value('access_token');

    expect($raw)->not->toBe('secret-token');
    expect($connection->fresh()->access_token)->toBe('secret-token');
});

test('a connection reports whether it is usable', function () {
    expect(BankConnection::factory()->create()->isActive())->toBeTrue();
    expect(BankConnection::factory()->pendingSca()->create()->isActive())->toBeFalse();
    expect(BankConnection::factory()->revoked()->create()->isActive())->toBeFalse();
    expect(BankConnection::factory()->expired()->create()->needsRefresh())->toBeTrue();
});

test('monzo and amex accounts hang off the same user', function () {
    $user = User::factory()->create();

    $monzo = Account::factory()->monzo()->for($user)->create();
    $amex = Account::factory()->amex()->for($user)->create();

    expect($user->accounts()->count())->toBe(2);
    expect($monzo->name)->toBe('Monzo');
    expect($amex->name)->toBe('Amex');
    expect($monzo->bank_connection_id)->not->toBeNull();
    /** Amex has no OAuth connection because its rows arrive by CSV. */
    expect($amex->bank_connection_id)->toBeNull();
});

test('transactions from both sources share one table and one shape', function () {
    $user = User::factory()->create();
    $monzo = Account::factory()->monzo()->for($user)->create();
    $amex = Account::factory()->amex()->for($user)->create();

    Transaction::factory()->forAccount($monzo)->create();
    Transaction::factory()->forAccount($amex)->create();

    expect($user->transactions()->count())->toBe(2);
    expect(Transaction::where('account_id', $monzo->id)->count())->toBe(1);
    expect(Transaction::where('account_id', $amex->id)->count())->toBe(1);
});

test('the same dedupe hash cannot be stored twice for one account', function () {
    $account = Account::factory()->create();

    Transaction::factory()->forAccount($account)->create(['dedupe_hash' => 'duplicate-hash']);

    expect(fn () => Transaction::factory()->forAccount($account)->create(['dedupe_hash' => 'duplicate-hash']))
        ->toThrow(UniqueConstraintViolationException::class);
});

test('the same dedupe hash may appear on different accounts', function () {
    $user = User::factory()->create();
    $monzo = Account::factory()->monzo()->for($user)->create();
    $amex = Account::factory()->amex()->for($user)->create();

    Transaction::factory()->forAccount($monzo)->create(['dedupe_hash' => 'shared-hash']);
    Transaction::factory()->forAccount($amex)->create(['dedupe_hash' => 'shared-hash']);

    expect(Transaction::where('dedupe_hash', 'shared-hash')->count())->toBe(2);
});

test('the accounting date is optional and round trips as a date', function () {
    expect(Transaction::factory()->create()->accounting_date)->toBeNull();

    $transaction = Transaction::factory()->create(['accounting_date' => '2026-05-20']);

    expect($transaction->fresh()->accounting_date->toDateString())->toBe('2026-05-20');
});

test('json columns round trip', function () {
    $transaction = Transaction::factory()->create([
        'tags' => ['#work', '#reimbursable'],
        'overrides' => ['category' => true],
    ]);

    $fresh = $transaction->fresh();

    expect($fresh->tags)->toBe(['#work', '#reimbursable']);
    expect($fresh->isOverridden('category'))->toBeTrue();
});

test('deleting a user cascades to their financial data', function () {
    $user = User::factory()->create();
    $account = Account::factory()->monzo()->for($user)->create();
    Transaction::factory()->forAccount($account)->create();
    CategoryRule::factory()->for($user)->create();
    CategoryTarget::factory()->for($user)->create();
    AmexSyncReport::factory()->for($user)->create();

    $user->delete();

    expect(Account::count())->toBe(0);
    expect(Transaction::count())->toBe(0);
    expect(CategoryRule::count())->toBe(0);
    expect(CategoryTarget::count())->toBe(0);
    expect(AmexSyncReport::count())->toBe(0);
});

/** One number per category per month is the whole shape of a target. */
test('one target per category per month', function () {
    $target = CategoryTarget::factory()->create(['category' => 'groceries', 'month' => '2026-08']);

    expect(fn () => CategoryTarget::factory()->create([
        'user_id' => $target->user_id,
        'category' => 'groceries',
        'month' => '2026-08',
    ]))->toThrow(UniqueConstraintViolationException::class);
});
