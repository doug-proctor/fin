<?php

use App\Models\Account;
use App\Models\Category;
use App\Models\CategoryRule;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The migration runs against rows that were already there while the Avolta
 * rule still existed, so each test puts that older state back and re-runs it.
 *
 * Part of that older state is the single `match_value` column, which
 * 2026_08_20_115144_replace_category_rule_match_value_with_match_values has
 * since replaced with a list. It is put back for the length of the run, filled
 * from the first string in each rule's list, and dropped again afterwards.
 */
function retireAvoltaRuleAndShoppingCategory(): void
{
    Schema::table('category_rules', function (Blueprint $table): void {
        $table->string('match_value')->nullable();
    });

    CategoryRule::query()->each(fn (CategoryRule $rule) => DB::table('category_rules')
        ->where('id', $rule->id)
        ->update(['match_value' => $rule->match_values[0] ?? null]));

    (require base_path('database/migrations/2026_08_20_113034_retire_the_avolta_rule_and_shopping_category.php'))->up();

    Schema::table('category_rules', function (Blueprint $table): void {
        $table->dropColumn('match_value');
    });
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->account = Account::factory()->monzo()->for($this->user)->create();
});

test('the avolta rule and the category it filed under both go', function () {
    $avolta = CategoryRule::factory()->for($this->user)->create([
        'name' => 'Avolta',
        'match_values' => ['AVOLTA'],
        'set_category' => 'shopping',
    ]);

    $shopping = Category::query()->create([
        'user_id' => $this->user->id,
        'value' => 'shopping',
        'label' => 'Shopping',
    ]);

    retireAvoltaRuleAndShoppingCategory();

    expect(CategoryRule::query()->whereKey($avolta->id)->exists())->toBeFalse();
    expect(Category::query()->whereKey($shopping->id)->exists())->toBeFalse();
});

/** A row filed under it by hand would be left with no name to show. */
test('the category stays when a transaction is already filed under it', function () {
    $shopping = Category::query()->create([
        'user_id' => $this->user->id,
        'value' => 'shopping',
        'label' => 'Shopping',
    ]);

    Transaction::factory()->forAccount($this->account)->create([
        'category' => 'shopping',
        'categorised_by' => 'user',
    ]);

    retireAvoltaRuleAndShoppingCategory();

    expect(Category::query()->whereKey($shopping->id)->exists())->toBeTrue();
});

/** Another user's transaction must not keep this user's category alive. */
test('the category is only kept by its own user rows', function () {
    $stranger = User::factory()->create();
    $strangerAccount = Account::factory()->monzo()->for($stranger)->create();

    $mine = Category::query()->create([
        'user_id' => $this->user->id,
        'value' => 'shopping',
        'label' => 'Shopping',
    ]);

    Transaction::factory()->forAccount($strangerAccount)->create(['category' => 'shopping']);

    retireAvoltaRuleAndShoppingCategory();

    expect(Category::query()->whereKey($mine->id)->exists())->toBeFalse();
});

/** A rule that only shares the name is somebody else's rule, not this one. */
test('a different rule named avolta is left alone', function () {
    $other = CategoryRule::factory()->for($this->user)->create([
        'name' => 'Avolta',
        'match_values' => ['AVOLTA DUTY FREE'],
        'set_category' => 'groceries',
    ]);

    retireAvoltaRuleAndShoppingCategory();

    expect(CategoryRule::query()->whereKey($other->id)->exists())->toBeTrue();
});
