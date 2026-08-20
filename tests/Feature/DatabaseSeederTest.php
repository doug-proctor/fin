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
            ->and($rule->match_values)->not->toBeEmpty()
            ->and($rule->set_category !== null || ! blank($rule->set_tags))->toBeTrue();

        if ($rule->day_of_month !== null) {
            expect($rule->day_of_month)->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(31);
        }

        foreach ($rule->match_values as $value) {
            expect($value)->toBeString()->not->toBe('');

            /** A pattern that will not compile silently matches nothing forever. */
            if ($rule->match_type === 'regex') {
                expect(@preg_match('/'.str_replace('/', '\/', $value).'/', ''))
                    ->not->toBeFalse($rule->name);
            }
        }
    });
});

it('seeds the categories and nothing else', function (): void {
    expect(Category::labelsFor($this->userId))->toEqual(Category::DEFAULTS);
});

/**
 * Every category a rule files under has to be declared, or the seeder writes
 * a rule pointing at nothing. The reverse does not hold: a category can exist
 * with no rule behind it, for filing transactions by hand.
 */
it('declares every category the rules file under', function (): void {
    $used = collect(CategoryRuleSeeder::CATEGORY_RULES)
        ->pluck('set_category')
        ->filter()
        ->unique();

    $declared = collect(Category::DEFAULTS)->keys();

    expect($used->diff($declared)->all())->toBe([]);
});
