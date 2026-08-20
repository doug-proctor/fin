<?php

use App\Models\Account;
use App\Models\Category;
use App\Models\CategoryRule;
use App\Models\Transaction;
use App\Models\User;

/**
 * The migration runs against rows that were already there when the app still
 * read Monzo's categorisation, so each test puts that older state back and
 * re-runs it.
 */
function stopUsingBankCategories(): void
{
    (require base_path('database/migrations/2026_08_20_090907_stop_using_bank_categories.php'))->up();
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->account = Account::factory()->monzo()->for($this->user)->create();
});

test('a row monzo filed is set back to uncategorised', function () {
    $rule = CategoryRule::factory()->for($this->user)->create(['set_category' => 'groceries']);

    $fromMonzo = Transaction::factory()->forAccount($this->account)->create([
        'category' => 'groceries',
        'categorised_by' => 'source',
        'category_rule_id' => $rule->id,
    ]);

    stopUsingBankCategories();

    $fromMonzo->refresh();

    expect($fromMonzo->category)->toBeNull();
    expect($fromMonzo->categorised_by)->toBeNull();
    expect($fromMonzo->category_rule_id)->toBeNull();
});

test('a row a rule or the user filed is left exactly as it was', function () {
    $byRule = Transaction::factory()->forAccount($this->account)->create([
        'category' => 'transport',
        'categorised_by' => 'rule',
    ]);

    $byUser = Transaction::factory()->forAccount($this->account)->create([
        'category' => 'personal_care',
        'categorised_by' => 'user',
    ]);

    $unfiled = Transaction::factory()->forAccount($this->account)->create([
        'category' => null,
        'categorised_by' => null,
    ]);

    stopUsingBankCategories();

    expect($byRule->refresh()->category)->toBe('transport');
    expect($byUser->refresh()->category)->toBe('personal_care');
    expect($unfiled->refresh()->category)->toBeNull();
});

test('a monzo id keeps its name but is re-valued as a category of the user own', function () {
    $category = Category::query()->create([
        'user_id' => $this->user->id,
        'value' => 'category_0000B87NKzENdqVoflYV3C',
        'label' => 'Trips',
    ]);

    $transaction = Transaction::factory()->forAccount($this->account)->create([
        'category' => 'category_0000B87NKzENdqVoflYV3C',
        'categorised_by' => 'rule',
    ]);

    $rule = CategoryRule::factory()->for($this->user)->create([
        'set_category' => 'category_0000B87NKzENdqVoflYV3C',
    ]);

    stopUsingBankCategories();

    expect($category->refresh()->value)->toBe('custom_trips');
    expect($category->label)->toBe('Trips');

    /** Everything pointing at the old id follows it in the same pass. */
    expect($transaction->refresh()->category)->toBe('custom_trips');
    expect($rule->refresh()->set_category)->toBe('custom_trips');
});

test('a monzo id the user never named falls back to a handle of its own', function () {
    $category = Category::query()->create([
        'user_id' => $this->user->id,
        'value' => 'category_0000B86WnKknuzF8vd1v9g',
        'label' => 'category_0000B86WnKknuzF8vd1v9g',
    ]);

    stopUsingBankCategories();

    /** A bank handle must not survive as either the value or the name. */
    expect($category->refresh()->value)->toStartWith('custom_');
    expect($category->value)->not->toContain('category_0000');
});

test('a re-valued category steps past a name already taken', function () {
    Category::query()->create([
        'user_id' => $this->user->id,
        'value' => 'custom_trips',
        'label' => 'Trips',
    ]);

    $fromMonzo = Category::query()->create([
        'user_id' => $this->user->id,
        'value' => 'category_0000B87NKzENdqVoflYV3C',
        'label' => 'Trips',
    ]);

    stopUsingBankCategories();

    expect($fromMonzo->refresh()->value)->toBe('custom_trips_2');
});

test('one user monzo categories are never repointed onto another user rows', function () {
    $other = User::factory()->create();
    $otherAccount = Account::factory()->monzo()->for($other)->create();

    Category::query()->create([
        'user_id' => $this->user->id,
        'value' => 'category_0000B87NKzENdqVoflYV3C',
        'label' => 'Trips',
    ]);

    $theirs = Transaction::factory()->forAccount($otherAccount)->create([
        'category' => 'category_0000B87NKzENdqVoflYV3C',
        'categorised_by' => 'rule',
    ]);

    stopUsingBankCategories();

    expect($theirs->refresh()->category)->toBe('category_0000B87NKzENdqVoflYV3C');
});
