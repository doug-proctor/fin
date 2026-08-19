<?php

use App\Models\Category;
use App\Models\CategoryRule;
use App\Models\Transaction;
use App\Models\User;
use Database\Seeders\CategoryRuleSeeder;
use Database\Seeders\DatabaseSeeder;

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
});

it('seeds the default user with their categorisation rules', function (string $name, string $matchValue, string $category): void {
    $rule = CategoryRule::query()->where('name', $name)->sole();

    expect($rule->user_id)->toBe(User::query()->value('id'))
        ->and($rule->account_id)->toBeNull()
        ->and($rule->match_field)->toBe('any')
        ->and($rule->match_type)->toBe('contains')
        ->and($rule->match_value)->toBe($matchValue)
        ->and($rule->set_category)->toBe($category)
        ->and($rule->priority)->toBe(0)
        ->and($rule->stops_processing)->toBeTrue()
        ->and($rule->is_active)->toBeTrue();
})->with([
    ['TFL', 'TFL TRAVEL CHARGE', 'transport'],
    ["Sainsbury's", "Sainsbury's", 'groceries'],
    ['Waitrose Beckenham', 'Waitrose Beckenham', 'groceries'],
    ['PAYMENT RECEIVED - THANK YOU', 'PAYMENT RECEIVED - THANK YOU', 'transfers'],
    ['TRAINLINE', 'TRAINLINE', 'category_0000B87NKzENdqVoflYV3C'],
    ['BOOKING.COM', 'BOOKING.COM', 'category_0000B87NKzENdqVoflYV3C'],
    ['SHOTSMITHS', 'SHOTSMITHS', 'groceries'],
]);

/**
 * The rules live in their own seeder so they can be re-run on their own. That
 * only holds if a second pass updates the rules already there rather than
 * seeding a duplicate set.
 */
it('can re-run the rule seeder without duplicating rules', function (): void {
    $before = CategoryRule::query()->count();

    $this->seed(CategoryRuleSeeder::class);

    expect(CategoryRule::query()->count())->toBe($before);
});

/**
 * The only rule that is not a plain "contains", because the AMEX statement
 * writes the airline both as "QATAR AIRWAYS" and as "5970#QATARAIRWAYS.COM".
 */
it('matches both spellings of the Qatar Airways rule', function (string $description): void {
    $rule = CategoryRule::query()->where('name', 'Qatar Airways')->sole();

    $transaction = Transaction::factory()->make(['description' => $description]);

    expect($rule->matches($transaction))->toBeTrue();
})->with([
    'QATAR AIRWAYS           DOHA',
    '5970#QATARAIRWAYS.COM Q LONDON',
]);

/** Every rule must file transactions under a category that actually exists. */
it('seeds rules that point at a real category', function (): void {
    $categories = Category::query()->where('user_id', User::query()->value('id'))->pluck('value');

    CategoryRule::query()->whereNotNull('set_category')->each(
        fn (CategoryRule $rule) => expect($categories)->toContain($rule->set_category),
    );
});

it('seeds the built-in Monzo categories', function (): void {
    expect(Category::labelsFor(User::query()->value('id')))
        ->toMatchArray(Category::MONZO_DEFAULTS);
});

it('seeds the renamed custom Monzo categories', function (string $value, string $label): void {
    $category = Category::query()->where('value', $value)->sole();

    expect($category->user_id)->toBe(User::query()->value('id'))
        ->and($category->label)->toBe($label);
})->with([
    ['category_0000B86WnKknuzF8vd1v9g', 'Subscriptions'],
    ['category_0000B86WxhUOFB3OnAn0y2', 'Mum'],
    ['category_0000B86Wu1Qy9CR4om4isT', 'Reverie'],
    ['category_0000B87NoI6kXL3914UzCr', 'James'],
    ['category_0000B86XIELXn5iyeVs6Zp', 'Social'],
]);
