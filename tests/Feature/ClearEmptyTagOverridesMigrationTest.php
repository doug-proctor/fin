<?php

use App\Actions\Transactions\ApplyCategoryRules;
use App\Models\Account;
use App\Models\CategoryRule;
use App\Models\Transaction;
use App\Models\User;

/**
 * The migration runs against rows edited while tags were still lifted out of
 * the note, so each test puts that older state back and re-runs it.
 */
function clearEmptyTagOverrides(): void
{
    (require base_path('database/migrations/2026_08_20_105533_clear_empty_tag_overrides_on_transactions.php'))->up();
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->account = Account::factory()->monzo()->for($this->user)->create();
});

test('a tags override protecting no tags is cleared', function () {
    $transaction = Transaction::factory()
        ->forAccount($this->account)
        ->overridden(['notes', 'tags'])
        ->create(['tags' => null]);

    clearEmptyTagOverrides();

    expect($transaction->refresh()->overrides)->toBe(['notes' => true]);
});

test('an override map holding nothing else is emptied to null', function () {
    $transaction = Transaction::factory()
        ->forAccount($this->account)
        ->overridden(['tags'])
        ->create(['tags' => []]);

    clearEmptyTagOverrides();

    expect($transaction->refresh()->overrides)->toBeNull();
});

test('a row the user tagged by hand keeps its override', function () {
    $transaction = Transaction::factory()
        ->forAccount($this->account)
        ->overridden(['tags'])
        ->create(['tags' => ['holiday']]);

    clearEmptyTagOverrides();

    expect($transaction->refresh()->overrides)->toBe(['tags' => true]);
});

test('every other override is left alone', function () {
    $transaction = Transaction::factory()
        ->forAccount($this->account)
        ->overridden(['category', 'notes'])
        ->create(['tags' => null]);

    clearEmptyTagOverrides();

    expect($transaction->refresh()->overrides)->toBe(['category' => true, 'notes' => true]);
});

test('a rule can tag a repaired row again', function () {
    $rule = CategoryRule::factory()->for($this->user)->create([
        'match_field' => 'any',
        'match_type' => 'contains',
        'match_values' => ['Purpleport'],
        'set_category' => null,
        'set_tags' => ['reverie'],
    ]);

    $transaction = Transaction::factory()
        ->forAccount($this->account)
        ->overridden(['notes', 'tags'])
        ->create([
            'name' => 'Purpleport',
            'merchant_name' => 'Purpleport',
            'tags' => null,
            'categorised_by' => 'rule',
        ]);

    /** The rule matches, and still cannot tag the row while the flag is on. */
    expect($rule->matches($transaction))->toBeTrue();

    app(ApplyCategoryRules::class)->handle($transaction);

    expect($transaction->tags)->toBeNull();

    clearEmptyTagOverrides();

    $repaired = $transaction->fresh();

    $applied = app(ApplyCategoryRules::class)->handle($repaired);

    expect($applied?->id)->toBe($rule->id);
    expect($repaired->tags)->toBe(['reverie']);
});
