<?php

use App\Models\Account;
use App\Models\CategoryRule;
use App\Models\Transaction;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create(['email' => 'doug@example.com']);
    $this->account = Account::factory()->monzo()->for($this->user)->create();
    $this->path = storage_path('app/testing-transaction-edits.json');

    if (is_file($this->path)) {
        unlink($this->path);
    }
});

afterEach(function () {
    if (is_file($this->path)) {
        unlink($this->path);
    }
});

/**
 * The whole point of the pair of commands: wipe every transaction, import them
 * again from the provider, and find the edits still on them.
 */
function reimport(Account $account, Transaction $original): Transaction
{
    Transaction::query()->delete();

    return Transaction::factory()->forAccount($account)->create([
        'dedupe_hash' => $original->dedupe_hash,
        'external_id' => $original->external_id,
        'booked_at' => $original->booked_at,
        'amount_minor' => $original->amount_minor,
        'name' => 'TESCO STORES',
        'description' => 'TESCO STORES 3456 LONDON',
        'notes' => null,
        'tags' => null,
        'category' => null,
        'categorised_by' => null,
        'accounting_date' => null,
        'overrides' => null,
    ]);
}

test('an edit survives a wipe and a re-import', function () {
    $original = Transaction::factory()->forAccount($this->account)->create([
        'dedupe_hash' => sha1('monzo:tx_123'),
        'name' => 'Weekly shop',
        'notes' => 'Split with Sam',
        'tags' => ['groceries', 'shared'],
        'category' => 'groceries',
        'categorised_by' => 'user',
        'accounting_date' => '2026-05-10',
        'processed' => true,
        'overrides' => ['name' => true, 'notes' => true, 'tags' => true],
    ]);

    $this->artisan('transactions:export-edits --path='.$this->path)->assertSuccessful();

    $restored = reimport($this->account, $original);

    $this->artisan('transactions:import-edits --path='.$this->path)->assertSuccessful();

    $restored->refresh();

    expect($restored->name)->toBe('Weekly shop')
        ->and($restored->notes)->toBe('Split with Sam')
        ->and($restored->tags)->toBe(['groceries', 'shared'])
        ->and($restored->category)->toBe('groceries')
        ->and($restored->categorised_by)->toBe('user')
        ->and($restored->accounting_date->toDateString())->toBe('2026-05-10')
        ->and($restored->processed)->toBeTrue()
        ->and($restored->overrides)->toBe(['name' => true, 'notes' => true, 'tags' => true]);
});

/** Ids are handed out again by the wipe, so nothing may be matched on one. */
test('it matches on provider and dedupe hash, not on ids', function () {
    $original = Transaction::factory()->forAccount($this->account)->create([
        'dedupe_hash' => sha1('monzo:tx_123'),
        'notes' => 'Kept',
        'overrides' => ['notes' => true],
    ]);

    $this->artisan('transactions:export-edits --path='.$this->path)->assertSuccessful();

    Transaction::query()->delete();
    $this->account->delete();
    $this->user->delete();

    $user = User::factory()->create(['email' => 'doug@example.com']);
    $account = Account::factory()->monzo()->for($user)->create();

    expect($account->id)->not->toBe($this->account->id);

    $restored = Transaction::factory()->forAccount($account)->create([
        'dedupe_hash' => $original->dedupe_hash,
        'notes' => null,
        'overrides' => null,
    ]);

    $this->artisan('transactions:import-edits --path='.$this->path)->assertSuccessful();

    expect($restored->fresh()->notes)->toBe('Kept');
});

/**
 * A rule files its rows again on the way in, so carrying its category would
 * only freeze a decision the rules are meant to keep making.
 */
test('a category set by a rule is not exported', function () {
    $rule = CategoryRule::factory()->for($this->user)->create();

    Transaction::factory()->forAccount($this->account)->create([
        'category' => 'groceries',
        'categorised_by' => 'rule',
        'category_rule_id' => $rule->id,
    ]);

    $this->artisan('transactions:export-edits --path='.$this->path)
        ->expectsOutputToContain('No transaction carries a hand edit')
        ->assertSuccessful();

    expect(is_file($this->path))->toBeFalse();
});

/**
 * ApplyCategoryRules reads overrides['category'] to decide whether it may file
 * a row. Rows edited before category stopped being a bank field still carry
 * the key; replaying it would block every rule against that row for good.
 */
test('a legacy category override is not carried across', function () {
    $original = Transaction::factory()->forAccount($this->account)->create([
        'dedupe_hash' => sha1('monzo:tx_123'),
        'category' => 'groceries',
        'categorised_by' => 'user',
        'overrides' => ['category' => true, 'notes' => true],
        'notes' => 'Kept',
    ]);

    $this->artisan('transactions:export-edits --path='.$this->path)->assertSuccessful();

    $restored = reimport($this->account, $original);

    $this->artisan('transactions:import-edits --path='.$this->path)->assertSuccessful();

    $restored->refresh();

    expect($restored->overrides)->toBe(['notes' => true])
        ->and($restored->isOverridden('category'))->toBeFalse()
        /** The category itself is still protected, by categorised_by. */
        ->and($restored->category)->toBe('groceries')
        ->and($restored->categorised_by)->toBe('user');
});

/** A field the user cleared is still a field they own. */
test('a field cleared by hand comes back cleared and still protected', function () {
    $original = Transaction::factory()->forAccount($this->account)->create([
        'dedupe_hash' => sha1('monzo:tx_123'),
        'description' => null,
        'overrides' => ['description' => true],
    ]);

    $this->artisan('transactions:export-edits --path='.$this->path)->assertSuccessful();

    $restored = reimport($this->account, $original);

    expect($restored->description)->toBe('TESCO STORES 3456 LONDON');

    $this->artisan('transactions:import-edits --path='.$this->path)->assertSuccessful();

    $restored->refresh();

    expect($restored->description)->toBeNull()
        ->and($restored->isOverridden('description'))->toBeTrue();
});

/** Marking a row off is an edit on its own, with no overrides map behind it. */
test('processed alone is enough to be exported', function () {
    $original = Transaction::factory()->forAccount($this->account)->processed()->create([
        'dedupe_hash' => sha1('monzo:tx_123'),
        'categorised_by' => null,
        'category' => null,
    ]);

    $this->artisan('transactions:export-edits --path='.$this->path)->assertSuccessful();

    $restored = reimport($this->account, $original);

    expect($restored->processed)->toBeFalse();

    $this->artisan('transactions:import-edits --path='.$this->path)->assertSuccessful();

    expect($restored->fresh()->processed)->toBeTrue();
});

/**
 * A Monzo row older than the 90 day window, or an AMEX statement not uploaded
 * yet, leaves its edits with nothing to attach to. Naming them is the only way
 * to act on it.
 */
test('it names an edit that matched no transaction', function () {
    Transaction::factory()->forAccount($this->account)->create([
        'dedupe_hash' => sha1('monzo:tx_gone'),
        'booked_at' => '2026-01-14 10:00:00',
        'amount_minor' => -4250,
        'description' => 'DELETED MERCHANT',
        'notes' => 'Kept',
        'overrides' => ['notes' => true],
    ]);

    $this->artisan('transactions:export-edits --path='.$this->path)->assertSuccessful();

    Transaction::query()->delete();

    $this->artisan('transactions:import-edits --path='.$this->path)
        ->expectsOutputToContain('1 edit matched no transaction')
        ->expectsOutputToContain('DELETED MERCHANT')
        ->assertSuccessful();
});

test('a dry run writes nothing', function () {
    $original = Transaction::factory()->forAccount($this->account)->create([
        'dedupe_hash' => sha1('monzo:tx_123'),
        'notes' => 'Kept',
        'overrides' => ['notes' => true],
    ]);

    $this->artisan('transactions:export-edits --path='.$this->path)->assertSuccessful();

    $restored = reimport($this->account, $original);

    $this->artisan('transactions:import-edits --dry-run --path='.$this->path)
        ->expectsOutputToContain('Would restore 1')
        ->assertSuccessful();

    expect($restored->fresh()->notes)->toBeNull();
});

test('it refuses a file written by a different version', function () {
    file_put_contents($this->path, json_encode(['version' => 99, 'edits' => []]));

    $this->artisan('transactions:import-edits --path='.$this->path)
        ->expectsOutputToContain('version 99')
        ->assertFailed();
});

test('it reports a missing file rather than throwing', function () {
    $this->artisan('transactions:import-edits --path='.storage_path('app/nope.json'))
        ->expectsOutputToContain('Run transactions:export-edits first')
        ->assertFailed();
});

/** Re-running the restore must not double up or drift. */
test('restoring twice is the same as restoring once', function () {
    $original = Transaction::factory()->forAccount($this->account)->create([
        'dedupe_hash' => sha1('monzo:tx_123'),
        'notes' => 'Kept',
        'tags' => ['shared'],
        'overrides' => ['notes' => true, 'tags' => true],
    ]);

    $this->artisan('transactions:export-edits --path='.$this->path)->assertSuccessful();

    $restored = reimport($this->account, $original);

    $this->artisan('transactions:import-edits --path='.$this->path)->assertSuccessful();
    $first = $restored->fresh()->only(['notes', 'tags', 'overrides']);

    $this->artisan('transactions:import-edits --path='.$this->path)->assertSuccessful();

    expect($restored->fresh()->only(['notes', 'tags', 'overrides']))->toBe($first);
});
