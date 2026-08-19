<?php

use App\Models\Category;
use App\Models\CategoryRule;
use App\Models\User;
use Database\Seeders\CategoryRuleSeeder;
use Database\Seeders\DatabaseSeeder;

/**
 * These assert what the seeders guarantee, never what they happen to contain.
 * The rule list is personal data that changes whenever a new merchant shows
 * up, so a test that restates any of it is a test that has to be edited every
 * time the list does. Everything here is derived from the seeder's own
 * constants or from the rules already in the database.
 */
beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);

    $this->userId = User::query()->value('id');
});

/**
 * Every declared rule reaches the database intact, with every value it
 * declared. sole() is doing real work: it fails just as loudly on a rule that
 * was never written as on one written twice.
 */
it('persists every rule the seeder declares', function (): void {
    foreach (CategoryRuleSeeder::CATEGORY_RULES as $declared) {
        $stored = CategoryRule::query()
            ->where('user_id', $this->userId)
            ->where('name', $declared['name'])
            ->where('day_of_month', $declared['day_of_month'] ?? null)
            ->sole();

        foreach ($declared as $column => $value) {
            expect($stored->{$column})->toBe($value, "{$declared['name']}.{$column}");
        }
    }
});

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

/** Every rule must file transactions under a category that actually exists. */
it('seeds rules that point at a real category', function (): void {
    $categories = Category::query()->where('user_id', $this->userId)->pluck('value');

    CategoryRule::query()->whereNotNull('set_category')->each(
        fn (CategoryRule $rule) => expect($categories)->toContain($rule->set_category),
    );
});

/**
 * The shape the rules form would refuse to save. A rule seeded straight into
 * the database never passes through that validation, so it is asserted here
 * instead.
 */
it('seeds rules the rules form would accept', function (): void {
    CategoryRule::query()->each(function (CategoryRule $rule): void {
        expect(CategoryRule::MATCH_FIELDS)->toContain($rule->match_field)
            ->and(CategoryRule::MATCH_TYPES)->toContain($rule->match_type)
            ->and($rule->match_value)->not->toBe('')
            ->and($rule->set_category !== null || ! blank($rule->set_tags))->toBeTrue();

        if ($rule->day_of_month !== null) {
            expect($rule->day_of_month)->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(31);
        }

        /** A pattern that will not compile silently matches nothing forever. */
        if ($rule->match_type === 'regex') {
            expect(@preg_match('/'.str_replace('/', '\/', $rule->match_value).'/', ''))
                ->not->toBeFalse($rule->name);
        }
    });
});

it('seeds the built-in Monzo categories', function (): void {
    expect(Category::labelsFor($this->userId))->toMatchArray(Category::MONZO_DEFAULTS);
});

it('seeds the renamed custom Monzo categories', function (): void {
    foreach (DatabaseSeeder::CUSTOM_CATEGORIES as $value => $label) {
        $category = Category::query()
            ->where('user_id', $this->userId)
            ->where('value', $value)
            ->sole();

        expect($category->label)->toBe($label);
    }
});
