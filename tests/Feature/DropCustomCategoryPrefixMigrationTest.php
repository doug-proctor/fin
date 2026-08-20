<?php

use App\Models\Account;
use App\Models\Category;
use App\Models\CategoryRule;
use App\Models\Transaction;
use App\Models\User;

/**
 * The migration runs against rows that were already there when a user-added
 * category still carried a `custom_` prefix, so each test puts that older
 * state back and re-runs it.
 */
function dropCustomCategoryPrefix(): void
{
    (require base_path('database/migrations/2026_08_20_112037_drop_custom_category_prefix.php'))->up();
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->account = Account::factory()->monzo()->for($this->user)->create();
});

test('the prefix is dropped and everything naming the old value follows it', function () {
    $category = Category::query()->create([
        'user_id' => $this->user->id,
        'value' => 'custom_reverie',
        'label' => 'Reverie',
    ]);

    $transaction = Transaction::factory()->forAccount($this->account)->create([
        'category' => 'custom_reverie',
        'categorised_by' => 'user',
    ]);

    $rule = CategoryRule::factory()->for($this->user)->create([
        'set_category' => 'custom_reverie',
    ]);

    dropCustomCategoryPrefix();

    expect($category->refresh()->value)->toBe('reverie');
    expect($category->label)->toBe('Reverie');
    expect($transaction->refresh()->category)->toBe('reverie');
    expect($rule->refresh()->set_category)->toBe('reverie');
});

test('an unprefixed value already taken is stepped past', function () {
    Category::query()->create([
        'user_id' => $this->user->id,
        'value' => 'reverie',
        'label' => 'Reverie',
    ]);

    $prefixed = Category::query()->create([
        'user_id' => $this->user->id,
        'value' => 'custom_reverie',
        'label' => 'Reverie again',
    ]);

    CategoryRule::factory()->for($this->user)->create(['set_category' => 'reverie']);
    CategoryRule::factory()->for($this->user)->create(['set_category' => 'custom_reverie']);

    dropCustomCategoryPrefix();

    expect($prefixed->refresh()->value)->toBe('reverie_2');
});

test('a category no rule and no transaction names is deleted', function () {
    $used = Category::ensure($this->user->id, 'groceries');
    $filed = Category::ensure($this->user->id, 'personal_care');

    $unused = Category::query()->create([
        'user_id' => $this->user->id,
        'value' => 'charity',
        'label' => 'Charity',
    ]);

    CategoryRule::factory()->for($this->user)->create(['set_category' => 'groceries']);
    Transaction::factory()->forAccount($this->account)->create(['category' => 'personal_care']);

    dropCustomCategoryPrefix();

    expect(Category::query()->whereKey($used->id)->exists())->toBeTrue();
    expect(Category::query()->whereKey($filed->id)->exists())->toBeTrue();
    expect(Category::query()->whereKey($unused->id)->exists())->toBeFalse();
});

/** One user's rules must never keep another user's category alive. */
test('a category is only kept by its own user rows', function () {
    $stranger = User::factory()->create();
    $strangerAccount = Account::factory()->monzo()->for($stranger)->create();

    $mine = Category::query()->create([
        'user_id' => $this->user->id,
        'value' => 'charity',
        'label' => 'Charity',
    ]);

    Transaction::factory()->forAccount($strangerAccount)->create(['category' => 'charity']);

    dropCustomCategoryPrefix();

    expect(Category::query()->whereKey($mine->id)->exists())->toBeFalse();
});
