<?php

use App\Models\CategoryRule;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The schema already carries the list, so each test winds the migration back
 * to the single column first and then runs it forwards again.
 */
function replaceMatchValueWithMatchValues(): Migration
{
    return require base_path('database/migrations/2026_08_20_115144_replace_category_rule_match_value_with_match_values.php');
}

beforeEach(function () {
    $this->user = User::factory()->create();
});

test('a rule written before the list becomes a list of the one string it looked for', function () {
    $rule = CategoryRule::factory()->for($this->user)->create([
        'match_values' => ['TESCO'],
    ]);

    $migration = replaceMatchValueWithMatchValues();
    $migration->down();

    expect(DB::table('category_rules')->where('id', $rule->id)->value('match_value'))
        ->toBe('TESCO');

    $migration->up();

    expect($rule->fresh()->match_values)->toBe(['TESCO']);
});

/** The old column holds one string, so going back keeps the first of them. */
test('winding back keeps the first string a rule looks for', function () {
    $rule = CategoryRule::factory()->for($this->user)->create([
        'match_values' => ['TESCO', 'LIDL'],
    ]);

    replaceMatchValueWithMatchValues()->down();

    expect(DB::table('category_rules')->where('id', $rule->id)->value('match_value'))
        ->toBe('TESCO');
});

test('every rule is converted, not just the first', function () {
    $rules = CategoryRule::factory()->for($this->user)->count(3)->create();
    $migration = replaceMatchValueWithMatchValues();

    $migration->down();
    $migration->up();

    $rules->each(fn (CategoryRule $rule) => expect($rule->fresh()->match_values)
        ->toBe($rule->match_values));
});
