<?php

use App\Models\Account;
use App\Models\Category;
use App\Models\CategoryRule;
use App\Models\Transaction;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->account = Account::factory()->monzo()->for($this->user)->create();
});

test('it clears the category, who set it and the rule that set it', function () {
    $rule = CategoryRule::factory()->for($this->user)->create();

    $transaction = Transaction::factory()->forAccount($this->account)->create([
        'category' => 'groceries',
        'categorised_by' => 'rule',
        'category_rule_id' => $rule->id,
    ]);

    $this->artisan('transactions:clear-categories --force')->assertSuccessful();

    $transaction->refresh();

    expect($transaction->category)->toBeNull()
        ->and($transaction->categorised_by)->toBeNull()
        ->and($transaction->category_rule_id)->toBeNull();
});

/**
 * The override says the user owns the field. Left behind with no category
 * under it, it would block every later rule run on that row forever, which is
 * the shape of bug the tags override already caused.
 */
test('it clears a category set by hand along with its override', function () {
    $transaction = Transaction::factory()->forAccount($this->account)->create([
        'category' => 'personal_care',
        'categorised_by' => 'user',
        'overrides' => ['category' => true],
    ]);

    $this->artisan('transactions:clear-categories --force')->assertSuccessful();

    expect($transaction->refresh()->category)->toBeNull()
        ->and($transaction->isOverridden('category'))->toBeFalse()
        ->and($transaction->overrides)->toBeNull();
});

test('it leaves every other override alone', function () {
    $transaction = Transaction::factory()->forAccount($this->account)->create([
        'category' => 'groceries',
        'overrides' => ['category' => true, 'notes' => true, 'tags' => true],
    ]);

    $this->artisan('transactions:clear-categories --force')->assertSuccessful();

    expect($transaction->refresh()->overrides)->toBe(['notes' => true, 'tags' => true]);
});

test('it leaves everything that is not the category alone', function () {
    $transaction = Transaction::factory()->forAccount($this->account)->create([
        'name' => 'TESCO STORES',
        'notes' => 'weekly shop',
        'tags' => ['household'],
        'processed' => true,
        'category' => 'groceries',
    ]);

    $this->artisan('transactions:clear-categories --force')->assertSuccessful();

    $transaction->refresh();

    expect($transaction->name)->toBe('TESCO STORES')
        ->and($transaction->notes)->toBe('weekly shop')
        ->and($transaction->tags)->toBe(['household'])
        ->and($transaction->processed)->toBeTrue();
});

test('it clears every transaction, not just the first', function () {
    Transaction::factory()->forAccount($this->account)->count(5)->create([
        'category' => 'groceries',
    ]);

    $this->artisan('transactions:clear-categories --force')
        ->expectsOutputToContain('Cleared the category from 5 transactions.')
        ->assertSuccessful();

    expect(Transaction::whereNotNull('category')->count())->toBe(0);
});

test('the user option limits it to that user', function () {
    $stranger = User::factory()->create();
    $strangerAccount = Account::factory()->monzo()->for($stranger)->create();

    $mine = Transaction::factory()->forAccount($this->account)->create(['category' => 'groceries']);
    $theirs = Transaction::factory()->forAccount($strangerAccount)->create(['category' => 'groceries']);

    $this->artisan("transactions:clear-categories --force --user={$this->user->id}")
        ->assertSuccessful();

    expect($mine->refresh()->category)->toBeNull()
        ->and($theirs->refresh()->category)->toBe('groceries');
});

test('it says so and stops when nothing is categorised', function () {
    Transaction::factory()->forAccount($this->account)->create(['category' => null]);

    $this->artisan('transactions:clear-categories --force')
        ->expectsOutputToContain('No transactions have a category.')
        ->assertSuccessful();
});

test('it asks first and changes nothing when the answer is no', function () {
    $transaction = Transaction::factory()->forAccount($this->account)->create([
        'category' => 'groceries',
    ]);

    $this->artisan('transactions:clear-categories')
        ->expectsConfirmation('Clear the category from 1 transaction? This cannot be undone.', 'no')
        ->assertFailed();

    expect($transaction->refresh()->category)->toBe('groceries');
});

test('answering yes clears them', function () {
    $transaction = Transaction::factory()->forAccount($this->account)->create([
        'category' => 'groceries',
    ]);

    $this->artisan('transactions:clear-categories')
        ->expectsConfirmation('Clear the category from 1 transaction? This cannot be undone.', 'yes')
        ->assertSuccessful();

    expect($transaction->refresh()->category)->toBeNull();
});

/** The command unfiles transactions; the user's own category list is not its business. */
test('it does not delete any category', function () {
    Transaction::factory()->forAccount($this->account)->create(['category' => 'groceries']);

    $before = Category::query()->where('user_id', $this->user->id)->count();

    $this->artisan('transactions:clear-categories --force')->assertSuccessful();

    expect(Category::query()->where('user_id', $this->user->id)->count())->toBe($before);
});
