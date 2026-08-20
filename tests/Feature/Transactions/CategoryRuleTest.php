<?php

use App\Actions\Transactions\ApplyCategoryRules;
use App\Models\Account;
use App\Models\CategoryRule;
use App\Models\Transaction;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->monzo = Account::factory()->monzo()->for($this->user)->create();
    $this->amex = Account::factory()->amex()->for($this->user)->create();
});

test('the rules page renders', function () {
    $this->actingAs($this->user)->get(route('category-rules.index'))->assertOk();
});

test('a rule categorises a transaction as it is imported', function () {
    CategoryRule::factory()->for($this->user)->create([
        'match_field' => 'any',
        'match_type' => 'contains',
        'match_values' => ['CAFFE NERO'],
        'set_category' => 'eating_out',
    ]);

    importFixture($this->amex, 'amex-no-reference.csv');

    $transaction = Transaction::where('description', 'CAFFE NERO')->first();

    expect($transaction->category)->toBe('eating_out');
    expect($transaction->categorised_by)->toBe('rule');
});

test('rules run in priority order and the first match can stop the rest', function () {
    CategoryRule::factory()->for($this->user)->create([
        'name' => 'catch all', 'priority' => 0, 'match_values' => ['TESCO'],
        'set_category' => 'trips', 'stops_processing' => true,
    ]);
    CategoryRule::factory()->for($this->user)->create([
        'name' => 'specific', 'priority' => 100, 'match_values' => ['TESCO'],
        'set_category' => 'groceries', 'stops_processing' => true,
    ]);

    $transaction = Transaction::factory()->forAccount($this->monzo)->make([
        'name' => 'TESCO STORES', 'category' => null,
    ]);

    app(ApplyCategoryRules::class)->handle($transaction);

    expect($transaction->category)->toBe('groceries');
});

test('a rule that does not stop lets a later rule add tags', function () {
    CategoryRule::factory()->for($this->user)->create([
        'priority' => 100, 'match_values' => ['TESCO'], 'stops_processing' => false,
        'set_category' => 'groceries', 'set_tags' => null,
    ]);
    CategoryRule::factory()->for($this->user)->create([
        'priority' => 50, 'match_values' => ['TESCO'], 'stops_processing' => true,
        'set_category' => null, 'set_tags' => ['household'],
    ]);

    $transaction = Transaction::factory()->forAccount($this->monzo)->make([
        'name' => 'TESCO STORES', 'category' => null, 'tags' => null,
    ]);

    app(ApplyCategoryRules::class)->handle($transaction);

    expect($transaction->category)->toBe('groceries');
    expect($transaction->tags)->toBe(['household']);
});

test('an inactive rule is ignored', function () {
    CategoryRule::factory()->for($this->user)->inactive()->create([
        'match_values' => ['TESCO'], 'set_category' => 'groceries',
    ]);

    $transaction = Transaction::factory()->forAccount($this->monzo)->make([
        'name' => 'TESCO STORES', 'category' => null,
    ]);

    app(ApplyCategoryRules::class)->handle($transaction);

    expect($transaction->category)->toBeNull();
});

test('a rule scoped to one account leaves the other alone', function () {
    CategoryRule::factory()->for($this->user)->create([
        'account_id' => $this->amex->id,
        'match_values' => ['SHELL'],
        'set_category' => 'transport',
    ]);

    $onMonzo = Transaction::factory()->forAccount($this->monzo)->make(['name' => 'SHELL', 'category' => null]);
    $onAmex = Transaction::factory()->forAccount($this->amex)->make(['name' => 'SHELL', 'category' => null]);

    $rules = app(ApplyCategoryRules::class);
    $rules->handle($onMonzo);
    $rules->handle($onAmex);

    expect($onMonzo->category)->toBeNull();
    expect($onAmex->category)->toBe('transport');
});

test('amount bounds narrow a rule', function () {
    CategoryRule::factory()->for($this->user)->create([
        'match_values' => ['AMAZON'],
        'amount_max_minor' => -10000,
        'set_category' => 'personal_care',
    ]);

    $small = Transaction::factory()->forAccount($this->monzo)->make(['name' => 'AMAZON', 'amount_minor' => -500, 'category' => null]);
    $large = Transaction::factory()->forAccount($this->monzo)->make(['name' => 'AMAZON', 'amount_minor' => -20000, 'category' => null]);

    $rules = app(ApplyCategoryRules::class);
    $rules->handle($small);
    $rules->handle($large);

    expect($small->category)->toBeNull();
    expect($large->category)->toBe('personal_care');
});

test('an exact amount narrows a rule', function () {
    CategoryRule::factory()->for($this->user)->create([
        'match_values' => ['NETFLIX'],
        'amount_minor' => -1099,
        'set_category' => 'subscriptions',
    ]);

    $exact = Transaction::factory()->forAccount($this->monzo)->make(['name' => 'NETFLIX', 'amount_minor' => -1099, 'category' => null]);
    $other = Transaction::factory()->forAccount($this->monzo)->make(['name' => 'NETFLIX', 'amount_minor' => -1599, 'category' => null]);

    /** The sign is part of the amount, so a refund of the same size is not a match. */
    $refund = Transaction::factory()->forAccount($this->monzo)->make(['name' => 'NETFLIX', 'amount_minor' => 1099, 'category' => null]);

    $rules = app(ApplyCategoryRules::class);
    $rules->handle($exact);
    $rules->handle($other);
    $rules->handle($refund);

    expect($exact->category)->toBe('subscriptions');
    expect($other->category)->toBeNull();
    expect($refund->category)->toBeNull();
});

test('a day of the month narrows a rule, whatever time of day it was booked', function () {
    CategoryRule::factory()->for($this->user)->create([
        'match_values' => ['RENT'],
        'day_of_month' => 1,
        'set_category' => 'personal_care',
    ]);

    $onTheDay = Transaction::factory()->forAccount($this->monzo)->make([
        'name' => 'RENT', 'booked_at' => '2026-03-01 23:45:00', 'category' => null,
    ]);
    $dayAfter = Transaction::factory()->forAccount($this->monzo)->make([
        'name' => 'RENT', 'booked_at' => '2026-03-02 00:15:00', 'category' => null,
    ]);

    $rules = app(ApplyCategoryRules::class);
    $rules->handle($onTheDay);
    $rules->handle($dayAfter);

    expect($onTheDay->category)->toBe('personal_care');
    expect($dayAfter->category)->toBeNull();
});

/** The whole point of the field: the month and year are not consulted. */
test('a day of the month matches that day in every month', function () {
    CategoryRule::factory()->for($this->user)->create([
        'match_values' => ['RENT'],
        'day_of_month' => 4,
        'set_category' => 'personal_care',
    ]);

    $march = Transaction::factory()->forAccount($this->monzo)->make([
        'name' => 'RENT', 'booked_at' => '2026-03-04 09:00:00', 'category' => null,
    ]);
    $november = Transaction::factory()->forAccount($this->monzo)->make([
        'name' => 'RENT', 'booked_at' => '2025-11-04 09:00:00', 'category' => null,
    ]);
    $fifth = Transaction::factory()->forAccount($this->monzo)->make([
        'name' => 'RENT', 'booked_at' => '2026-03-05 09:00:00', 'category' => null,
    ]);

    $rules = app(ApplyCategoryRules::class);
    $rules->handle($march);
    $rules->handle($november);
    $rules->handle($fifth);

    expect($march->category)->toBe('personal_care');
    expect($november->category)->toBe('personal_care');
    expect($fifth->category)->toBeNull();
});

/** Both conditions narrow the same rule, so both have to hold. */
test('the exact amount and day of the month conditions combine', function () {
    CategoryRule::factory()->for($this->user)->create([
        'match_values' => ['RENT'],
        'amount_minor' => -95000,
        'day_of_month' => 1,
        'set_category' => 'personal_care',
    ]);

    $both = Transaction::factory()->forAccount($this->monzo)->make([
        'name' => 'RENT', 'amount_minor' => -95000, 'booked_at' => '2026-03-01 09:00:00', 'category' => null,
    ]);
    $rightDayWrongAmount = Transaction::factory()->forAccount($this->monzo)->make([
        'name' => 'RENT', 'amount_minor' => -80000, 'booked_at' => '2026-03-01 09:00:00', 'category' => null,
    ]);
    $rightAmountWrongDay = Transaction::factory()->forAccount($this->monzo)->make([
        'name' => 'RENT', 'amount_minor' => -95000, 'booked_at' => '2026-04-02 09:00:00', 'category' => null,
    ]);

    $rules = app(ApplyCategoryRules::class);
    $rules->handle($both);
    $rules->handle($rightDayWrongAmount);
    $rules->handle($rightAmountWrongDay);

    expect($both->category)->toBe('personal_care');
    expect($rightDayWrongAmount->category)->toBeNull();
    expect($rightAmountWrongDay->category)->toBeNull();
});

/** Left null they must not narrow anything, or every existing rule breaks. */
test('a rule with neither condition set still matches on text alone', function () {
    CategoryRule::factory()->for($this->user)->create([
        'match_values' => ['RENT'],
        'amount_minor' => null,
        'day_of_month' => null,
        'set_category' => 'personal_care',
    ]);

    $transaction = Transaction::factory()->forAccount($this->monzo)->make(['name' => 'RENT', 'category' => null]);

    app(ApplyCategoryRules::class)->handle($transaction);

    expect($transaction->category)->toBe('personal_care');
});

test('the exact conditions can be saved from the rules form', function () {
    $this->actingAs($this->user)
        ->from(route('category-rules.index'))
        ->post(route('category-rules.store'), [
            'name' => 'March rent',
            'match_field' => 'any',
            'match_type' => 'contains',
            'match_values' => ['RENT'],
            'amount_minor' => -95000,
            'day_of_month' => 1,
            'set_category' => 'personal_care',
        ])
        ->assertSessionHasNoErrors();

    $rule = CategoryRule::query()->where('name', 'March rent')->sole();

    expect($rule->amount_minor)->toBe(-95000);
    expect($rule->day_of_month)->toBe(1);
});

test('a day of the month outside the calendar is refused', function () {
    $this->actingAs($this->user)
        ->from(route('category-rules.index'))
        ->post(route('category-rules.store'), [
            'name' => 'Impossible',
            'match_field' => 'any',
            'match_type' => 'contains',
            'match_values' => ['RENT'],
            'day_of_month' => 32,
            'set_category' => 'personal_care',
        ])
        ->assertSessionHasErrors('day_of_month');
});

/**
 * Both fields are optional, and the form sends null for a blank one. Zero and
 * an empty string would each be a condition rather than the absence of one.
 */
test('leaving the exact conditions blank stores no condition', function () {
    $this->actingAs($this->user)
        ->from(route('category-rules.index'))
        ->post(route('category-rules.store'), [
            'name' => 'Any rent',
            'match_field' => 'any',
            'match_type' => 'contains',
            'match_values' => ['RENT'],
            'amount_minor' => null,
            'day_of_month' => null,
            'set_category' => 'personal_care',
        ])
        ->assertSessionHasNoErrors();

    $rule = CategoryRule::query()->where('name', 'Any rent')->sole();

    expect($rule->amount_minor)->toBeNull();
    expect($rule->day_of_month)->toBeNull();
});

test('amount bounds can be saved from the rules form', function () {
    $this->actingAs($this->user)
        ->from(route('category-rules.index'))
        ->post(route('category-rules.store'), [
            'name' => 'Big spends',
            'match_field' => 'any',
            'match_type' => 'contains',
            'match_values' => ['AMAZON'],
            'amount_min_minor' => -5000,
            'amount_max_minor' => -1000,
            'set_category' => 'personal_care',
        ])
        ->assertSessionHasNoErrors();

    $rule = CategoryRule::query()->where('name', 'Big spends')->sole();

    expect($rule->amount_min_minor)->toBe(-5000);
    expect($rule->amount_max_minor)->toBe(-1000);
});

/** The two describe the same thing, so together they are a mistake. */
test('an exact amount and a range cannot both be set', function () {
    $this->actingAs($this->user)
        ->from(route('category-rules.index'))
        ->post(route('category-rules.store'), [
            'name' => 'Contradictory',
            'match_field' => 'any',
            'match_type' => 'contains',
            'match_values' => ['AMAZON'],
            'amount_minor' => -1500,
            'amount_min_minor' => -5000,
            'set_category' => 'personal_care',
        ])
        ->assertSessionHasErrors('amount_minor');

    expect(CategoryRule::query()->where('name', 'Contradictory')->exists())->toBeFalse();
});

/** A range the wrong way round matches nothing at all. */
test('a reversed range is rejected', function () {
    $this->actingAs($this->user)
        ->from(route('category-rules.index'))
        ->post(route('category-rules.store'), [
            'name' => 'Backwards',
            'match_field' => 'any',
            'match_type' => 'contains',
            'match_values' => ['AMAZON'],
            'amount_min_minor' => -1000,
            'amount_max_minor' => -5000,
            'set_category' => 'personal_care',
        ])
        ->assertSessionHasErrors('amount_max_minor');
});

/** Zero is a real amount, so it must not read as an absent condition. */
test('a bound of zero is kept', function () {
    $this->actingAs($this->user)
        ->from(route('category-rules.index'))
        ->post(route('category-rules.store'), [
            'name' => 'Refunds and free',
            'match_field' => 'any',
            'match_type' => 'contains',
            'match_values' => ['AMAZON'],
            'amount_min_minor' => 0,
            'set_category' => 'personal_care',
        ])
        ->assertSessionHasNoErrors();

    expect(CategoryRule::query()->where('name', 'Refunds and free')->sole()->amount_min_minor)->toBe(0);
});

test('the rules listing carries the exact conditions', function () {
    CategoryRule::factory()->for($this->user)->create([
        'name' => 'March rent',
        'amount_minor' => -95000,
        'day_of_month' => 1,
    ]);

    $this->actingAs($this->user)
        ->get(route('category-rules.index'))
        ->assertInertia(fn ($page) => $page
            ->where('rules.0.amountMinor', -95000)
            ->where('rules.0.dayOfMonth', 1)
            ->has('rules.0.amountMinMinor')
            ->has('rules.0.amountMaxMinor')
        );
});

/**
 * The count is what the rule selects, not what it has categorised, so a rule
 * an earlier stops_processing rule shadows still reports its own matches.
 */
test('the rules listing counts the transactions each rule matches', function () {
    $broad = CategoryRule::factory()->for($this->user)->create([
        'name' => 'Anything AWS',
        'match_values' => ['AWS'],
        'priority' => 10,
        'stops_processing' => true,
    ]);
    $shadowed = CategoryRule::factory()->for($this->user)->create([
        'name' => 'AWS invoices only',
        'match_values' => ['AWS INVOICE'],
        'priority' => 0,
    ]);
    $never = CategoryRule::factory()->for($this->user)->create([
        'name' => 'Nothing at all',
        'match_values' => ['NO SUCH MERCHANT'],
        'priority' => -10,
    ]);

    Transaction::factory()->forAccount($this->monzo)->count(2)->create([
        'user_id' => $this->user->id, 'name' => 'AWS INVOICE', 'description' => null, 'merchant_name' => null,
    ]);
    Transaction::factory()->forAccount($this->monzo)->create([
        'user_id' => $this->user->id, 'name' => 'AWS EMEA', 'description' => null, 'merchant_name' => null,
    ]);

    $counts = collect($this->actingAs($this->user)
        ->get(route('category-rules.index'))
        ->viewData('page')['props']['rules'])
        ->pluck('matchCount', 'id');

    expect($counts[$broad->id])->toBe(3)
        ->and($counts[$shadowed->id])->toBe(2)
        ->and($counts[$never->id])->toBe(0);
});

test('match types behave as named', function () {
    $transaction = Transaction::factory()->forAccount($this->monzo)->make([
        'name' => 'Tesco Stores 3297', 'description' => null, 'merchant_name' => null,
    ]);

    $cases = [
        ['contains', 'tesco', true],
        ['contains', 'sainsbury', false],
        ['equals', 'tesco stores 3297', true],
        ['equals', 'tesco', false],
        ['starts_with', 'tesco', true],
        ['starts_with', 'stores', false],
        ['regex', '^tesco.*[0-9]{4}$', true],
        ['regex', '^sainsbury', false],
    ];

    foreach ($cases as [$type, $value, $expected]) {
        $rule = CategoryRule::factory()->for($this->user)->make([
            'match_field' => 'any', 'match_type' => $type, 'match_values' => [$value],
        ]);

        expect($rule->matches($transaction))->toBe($expected, "{$type} '{$value}'");
    }
});

test('a malformed regular expression does not blow up an import', function () {
    $rule = CategoryRule::factory()->for($this->user)->make([
        'match_type' => 'regex', 'match_values' => ['([unclosed'],
    ]);

    $transaction = Transaction::factory()->forAccount($this->monzo)->make(['name' => 'anything']);

    expect($rule->matches($transaction))->toBeFalse();
});

test('re-applying rules never overwrites a category set by hand', function () {
    $byHand = Transaction::factory()->forAccount($this->monzo)->create([
        'name' => 'TESCO STORES',
        'category' => 'personal_care',
        'categorised_by' => 'user',
        'overrides' => ['category' => true],
    ]);
    $untouched = Transaction::factory()->forAccount($this->monzo)->create([
        'name' => 'TESCO STORES', 'category' => null, 'categorised_by' => null,
    ]);

    CategoryRule::factory()->for($this->user)->create([
        'match_values' => ['TESCO'], 'set_category' => 'groceries',
    ]);

    $this->actingAs($this->user)
        ->from(route('category-rules.index'))
        ->post(route('category-rules.apply'), ['only_uncategorised' => false])
        ->assertSessionHasNoErrors();

    expect($byHand->fresh()->category)->toBe('personal_care');
    expect($untouched->fresh()->category)->toBe('groceries');
});

test('rules give amex and monzo rows the same category', function () {
    CategoryRule::factory()->for($this->user)->create([
        'match_values' => ['TESCO'], 'set_category' => 'groceries',
    ]);

    Transaction::factory()->forAccount($this->monzo)->create(['name' => 'TESCO STORES', 'category' => null]);
    Transaction::factory()->forAccount($this->amex)->create(['name' => 'TESCO STORES 3297', 'category' => null]);

    $this->actingAs($this->user)
        ->from(route('category-rules.index'))
        ->post(route('category-rules.apply'));

    expect(Transaction::where('category', 'groceries')->count())->toBe(2);
});

test('a rule can be created, edited and deleted', function () {
    $this->actingAs($this->user)
        ->post(route('category-rules.store'), [
            'name' => 'Groceries',
            'match_field' => 'any',
            'match_type' => 'contains',
            'match_values' => ['tesco'],
            'set_category' => 'groceries',
            'priority' => 10,
            'stops_processing' => true,
            'is_active' => true,
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('category-rules.index'));

    $rule = CategoryRule::first();
    expect($rule->name)->toBe('Groceries');

    $this->actingAs($this->user)
        ->patch(route('category-rules.update', $rule), [
            'name' => 'Supermarkets',
            'match_field' => 'any',
            'match_type' => 'contains',
            'match_values' => ['tesco'],
            'set_category' => 'groceries',
            'stops_processing' => true,
            'is_active' => true,
        ])
        ->assertSessionHasNoErrors();

    expect($rule->fresh()->name)->toBe('Supermarkets');

    $this->actingAs($this->user)
        ->delete(route('category-rules.destroy', $rule))
        ->assertSessionHasNoErrors();

    expect(CategoryRule::count())->toBe(0);
});

test('a rule that applies nothing is rejected', function () {
    $this->actingAs($this->user)
        ->post(route('category-rules.store'), [
            'name' => 'Does nothing',
            'match_field' => 'any',
            'match_type' => 'contains',
            'match_values' => ['tesco'],
        ])
        ->assertSessionHasErrors('set_category');
});

test('an invalid regular expression is rejected at the form', function () {
    $this->actingAs($this->user)
        ->post(route('category-rules.store'), [
            'name' => 'Broken',
            'match_field' => 'any',
            'match_type' => 'regex',
            'match_values' => ['([unclosed'],
            'set_category' => 'groceries',
        ])
        ->assertSessionHasErrors('match_values.0');
});

test('you cannot edit or delete another user rule', function () {
    $other = User::factory()->create();
    $rule = CategoryRule::factory()->for($other)->create(['name' => 'Theirs']);

    $this->actingAs($this->user)
        ->patch(route('category-rules.update', $rule), [
            'name' => 'Mine', 'match_field' => 'any', 'match_type' => 'contains',
            'match_values' => ['x'], 'set_category' => 'groceries',
        ])
        ->assertForbidden();

    $this->actingAs($this->user)
        ->delete(route('category-rules.destroy', $rule))
        ->assertForbidden();

    expect($rule->fresh()->name)->toBe('Theirs');
});

test('the rules page reports how many transactions a re-apply would change', function () {
    /** Two the rules may change: one a rule already filed, one unfiled... */
    Transaction::factory()->forAccount($this->monzo)->create(['categorised_by' => 'rule']);
    Transaction::factory()->forAccount($this->monzo)->create(['categorised_by' => null]);
    /** ...and one the user owns, which they may not. */
    Transaction::factory()->forAccount($this->monzo)->create(['categorised_by' => 'user']);

    $this->actingAs($this->user)
        ->get(route('category-rules.index'))
        ->assertInertia(fn ($page) => $page->where('recategorisableCount', 2));
});

test('the reported count matches what a re-apply actually touches', function () {
    Transaction::factory()->forAccount($this->monzo)->count(3)->create([
        'name' => 'TESCO STORES',
        'category' => 'trips',
        'categorised_by' => 'rule',
    ]);
    Transaction::factory()->forAccount($this->monzo)->create([
        'name' => 'TESCO STORES',
        'category' => 'personal_care',
        'categorised_by' => 'user',
    ]);

    CategoryRule::factory()->for($this->user)->create([
        'match_values' => ['TESCO'], 'set_category' => 'groceries',
    ]);

    $this->actingAs($this->user)
        ->get(route('category-rules.index'))
        ->assertInertia(fn ($page) => $page->where('recategorisableCount', 3));

    $this->actingAs($this->user)
        ->from(route('category-rules.index'))
        ->post(route('category-rules.apply'), ['only_uncategorised' => false]);

    expect(Transaction::where('category', 'groceries')->count())->toBe(3);
    expect(Transaction::where('categorised_by', 'user')->first()->category)
        ->toBe('personal_care');
});

test('deleting a rule leaves the categories it already applied alone', function () {
    $rule = CategoryRule::factory()->for($this->user)->create([
        'match_values' => ['TESCO'], 'set_category' => 'groceries',
    ]);

    $transactions = Transaction::factory()->forAccount($this->monzo)->count(3)->create([
        'category' => 'groceries',
        'categorised_by' => 'rule',
        'category_rule_id' => $rule->id,
    ]);

    $this->actingAs($this->user)
        ->delete(route('category-rules.destroy', $rule))
        ->assertSessionHasNoErrors();

    expect(CategoryRule::count())->toBe(0);

    /**
     * The rule going away must not take the categorisation with it, which is
     * what the confirmation dialog promises.
     */
    $transactions->each(function (Transaction $transaction) {
        $transaction->refresh();

        expect($transaction->exists)->toBeTrue();
        expect($transaction->category)->toBe('groceries');
        expect($transaction->category_rule_id)->toBeNull();
    });
});

test('a rule renames the transactions it matches', function () {
    CategoryRule::factory()->for($this->user)->create([
        'match_values' => ['TESCO'], 'set_category' => 'groceries', 'set_name' => 'Tesco',
    ]);

    $transaction = Transaction::factory()->forAccount($this->monzo)->make([
        'name' => 'TESCO STORES 3297', 'category' => null,
    ]);

    app(ApplyCategoryRules::class)->handle($transaction);

    expect($transaction->name)->toBe('Tesco');
    expect($transaction->category)->toBe('groceries');
});

test('a rename leaves a name the user has edited alone', function () {
    CategoryRule::factory()->for($this->user)->create([
        'match_field' => 'description', 'match_values' => ['TESCO'], 'set_name' => 'Tesco',
    ]);

    $transaction = Transaction::factory()->forAccount($this->monzo)->make([
        'name' => 'Weekly shop',
        'description' => 'TESCO STORES 3297',
        'overrides' => ['name' => true],
    ]);

    app(ApplyCategoryRules::class)->handle($transaction);

    expect($transaction->name)->toBe('Weekly shop');
});

test('a rule may rename without setting a category', function () {
    $this->actingAs($this->user)
        ->from(route('category-rules.index'))
        ->post(route('category-rules.store'), [
            'name' => 'Tidy Tesco',
            'match_field' => 'any',
            'match_type' => 'contains',
            'match_values' => ['TESCO'],
            'set_name' => 'Tesco',
        ])
        ->assertSessionHasNoErrors();

    expect(CategoryRule::query()->where('name', 'Tidy Tesco')->sole()->set_name)->toBe('Tesco');
});

/**
 * A rule's tags go through the same normalisation as a hand edited
 * transaction's, so a rule can never invent a second spelling of a tag that
 * already exists. See "Notes and tags are separate fields" in
 * .ai/rules/actions-transactions.md.
 */
test('a rule stores its tags the one way however they were typed', function () {
    $this->actingAs($this->user)
        ->from(route('category-rules.index'))
        ->post(route('category-rules.store'), [
            'name' => 'Client dinners',
            'match_field' => 'any',
            'match_type' => 'contains',
            'match_values' => ['DISHOOM'],
            'set_tags' => ['#Work Lunch', ' billable ', 'work-lunch'],
        ])
        ->assertSessionHasNoErrors();

    expect(CategoryRule::query()->where('name', 'Client dinners')->sole()->set_tags)
        ->toBe(['work-lunch', 'billable']);
});

test('a rule may set tags without setting a category', function () {
    $this->actingAs($this->user)
        ->from(route('category-rules.index'))
        ->post(route('category-rules.store'), [
            'name' => 'Tag Tesco',
            'match_field' => 'any',
            'match_type' => 'contains',
            'match_values' => ['TESCO'],
            'set_tags' => ['household'],
        ])
        ->assertSessionHasNoErrors();

    expect(CategoryRule::query()->where('name', 'Tag Tesco')->sole()->set_tags)->toBe(['household']);
});

/**
 * The form sends the list on every save, so an emptied one has to clear the
 * stored tags rather than read as "sets no tags, leave what is there".
 */
test('clearing a rule tags stores null and is refused on its own', function () {
    $rule = CategoryRule::factory()->for($this->user)->create([
        'set_category' => 'groceries',
        'set_tags' => ['household'],
    ]);

    $this->actingAs($this->user)
        ->from(route('category-rules.index'))
        ->patch(route('category-rules.update', $rule), [
            'name' => $rule->name,
            'match_field' => 'any',
            'match_type' => 'contains',
            'match_values' => $rule->match_values,
            'set_category' => 'groceries',
            'set_tags' => [],
        ])
        ->assertSessionHasNoErrors();

    expect($rule->fresh()->set_tags)->toBeNull();

    /** With the category gone too, the rule would apply nothing at all. */
    $this->actingAs($this->user)
        ->from(route('category-rules.index'))
        ->patch(route('category-rules.update', $rule), [
            'name' => $rule->name,
            'match_field' => 'any',
            'match_type' => 'contains',
            'match_values' => $rule->match_values,
            'set_tags' => [],
        ])
        ->assertSessionHasErrors('set_category');
});

test('a rule tag that is too long is rejected', function () {
    $this->actingAs($this->user)
        ->from(route('category-rules.index'))
        ->post(route('category-rules.store'), [
            'name' => 'Too long',
            'match_field' => 'any',
            'match_type' => 'contains',
            'match_values' => ['TESCO'],
            'set_tags' => [str_repeat('a', 51)],
        ])
        ->assertSessionHasErrors('set_tags.0');
});

/**
 * A rule written before its tags were normalised can still hold a second
 * spelling, so applying one folds it in rather than adding it alongside the
 * tag the row already carries.
 */
test('applying a rule never adds a second spelling of a tag the row has', function () {
    CategoryRule::factory()->for($this->user)->create([
        'match_values' => ['TESCO'],
        'set_category' => null,
        'set_tags' => ['Work Lunch', 'household'],
    ]);

    $transaction = Transaction::factory()->forAccount($this->monzo)->make([
        'name' => 'TESCO STORES', 'category' => null, 'tags' => ['work-lunch'],
    ]);

    app(ApplyCategoryRules::class)->handle($transaction);

    expect($transaction->tags)->toBe(['work-lunch', 'household']);
});

test('the rules page offers every tag in use, from transactions and from rules', function () {
    Transaction::factory()->forAccount($this->monzo)->create(['tags' => ['groceries']]);

    CategoryRule::factory()->for($this->user)->create([
        'set_category' => 'groceries',
        'set_tags' => ['household'],
    ]);

    $this->actingAs($this->user)
        ->get(route('category-rules.index'))
        ->assertInertia(fn ($page) => $page->where('tags', ['groceries', 'household']));
});

test('the rules listing carries the tags', function () {
    CategoryRule::factory()->for($this->user)->create([
        'set_category' => 'groceries',
        'set_tags' => ['household'],
    ]);

    $this->actingAs($this->user)
        ->get(route('category-rules.index'))
        ->assertInertia(fn ($page) => $page->where('rules.0.setTags', ['household']));
});

test('the rules listing carries the rename', function () {
    CategoryRule::factory()->for($this->user)->create(['set_name' => 'Tesco']);

    $this->actingAs($this->user)
        ->get(route('category-rules.index'))
        ->assertInertia(fn ($page) => $page->where('rules.0.setName', 'Tesco'));
});

/**
 * The edit dialog posts the whole rule back, including the fields it did not
 * change, so a blank optional field has to clear the stored one rather than
 * be ignored.
 */
test('editing a rule clears the conditions left blank', function () {
    $rule = CategoryRule::factory()->for($this->user)->create([
        'amount_min_minor' => -5000,
        'amount_max_minor' => -1000,
        'day_of_month' => 4,
        'account_id' => $this->amex->id,
        'set_category' => 'groceries',
    ]);

    $this->actingAs($this->user)
        ->patch(route('category-rules.update', $rule), [
            'name' => $rule->name,
            'match_field' => 'any',
            'match_type' => 'contains',
            'match_values' => ['tesco'],
            'amount_min_minor' => null,
            'amount_max_minor' => null,
            'amount_minor' => null,
            'day_of_month' => null,
            'account_id' => null,
            'set_category' => null,
            'set_name' => 'Tesco',
            'priority' => 0,
            'stops_processing' => true,
            'is_active' => true,
        ])
        ->assertSessionHasNoErrors();

    $rule->refresh();

    expect($rule->amount_min_minor)->toBeNull();
    expect($rule->amount_max_minor)->toBeNull();
    expect($rule->day_of_month)->toBeNull();
    expect($rule->account_id)->toBeNull();
    expect($rule->set_category)->toBeNull();
    expect($rule->set_name)->toBe('Tesco');
});

/** The dialog sends the rule's current state back, so an off rule stays off. */
test('editing an inactive rule leaves it inactive', function () {
    $rule = CategoryRule::factory()->for($this->user)->inactive()->create([
        'set_category' => 'groceries',
    ]);

    $this->actingAs($this->user)
        ->patch(route('category-rules.update', $rule), [
            'name' => 'Renamed',
            'match_field' => 'any',
            'match_type' => 'contains',
            'match_values' => ['tesco'],
            'set_category' => 'groceries',
            'stops_processing' => $rule->stops_processing,
            'is_active' => false,
        ])
        ->assertSessionHasNoErrors();

    expect($rule->fresh()->is_active)->toBeFalse();
    expect($rule->fresh()->name)->toBe('Renamed');
});

test('unchecking stop processing is saved', function () {
    $rule = CategoryRule::factory()->for($this->user)->create([
        'stops_processing' => true, 'set_category' => 'groceries',
    ]);

    $this->actingAs($this->user)
        ->patch(route('category-rules.update', $rule), [
            'name' => $rule->name,
            'match_field' => 'any',
            'match_type' => 'contains',
            'match_values' => ['tesco'],
            'set_category' => 'groceries',
            'stops_processing' => false,
            'is_active' => true,
        ])
        ->assertSessionHasNoErrors();

    expect($rule->fresh()->stops_processing)->toBeFalse();
});

test('applying one rule runs that rule and no other', function () {
    $tesco = CategoryRule::factory()->for($this->user)->create([
        'match_values' => ['TESCO'], 'set_category' => 'groceries',
    ]);
    CategoryRule::factory()->for($this->user)->create([
        'match_values' => ['TFL'], 'set_category' => 'transport',
    ]);

    $groceries = Transaction::factory()->forAccount($this->monzo)->create([
        'name' => 'TESCO STORES', 'category' => null, 'categorised_by' => null,
    ]);
    $travel = Transaction::factory()->forAccount($this->monzo)->create([
        'name' => 'TFL TRAVEL CHARGE', 'category' => null, 'categorised_by' => null,
    ]);

    $this->actingAs($this->user)
        ->from(route('category-rules.index'))
        ->post(route('category-rules.apply-one', $tesco))
        ->assertSessionHasNoErrors();

    expect($groceries->fresh()->category)->toBe('groceries');
    expect($travel->fresh()->category)->toBeNull();
});

test('applying one rule reaches rows another rule already categorised', function () {
    $rule = CategoryRule::factory()->for($this->user)->create([
        'match_values' => ['Purpleport'],
        'set_category' => null,
        'set_tags' => ['reverie'],
    ]);

    $transaction = Transaction::factory()->forAccount($this->monzo)->create([
        'name' => 'Purpleport',
        'category' => 'subscriptions',
        'categorised_by' => 'rule',
        'tags' => null,
    ]);

    $this->actingAs($this->user)
        ->from(route('category-rules.index'))
        ->post(route('category-rules.apply-one', $rule));

    expect($transaction->fresh()->tags)->toBe(['reverie']);
    expect($transaction->fresh()->category)->toBe('subscriptions');
});

test('applying one rule leaves a category set by hand alone', function () {
    $rule = CategoryRule::factory()->for($this->user)->create([
        'match_values' => ['TESCO'], 'set_category' => 'groceries',
    ]);

    $byHand = Transaction::factory()->forAccount($this->monzo)->create([
        'name' => 'TESCO STORES',
        'category' => 'personal_care',
        'categorised_by' => 'user',
        'overrides' => ['category' => true],
    ]);

    $this->actingAs($this->user)
        ->from(route('category-rules.index'))
        ->post(route('category-rules.apply-one', $rule));

    expect($byHand->fresh()->category)->toBe('personal_care');
});

test('one user cannot apply another user\'s rule', function () {
    $stranger = User::factory()->create();
    $rule = CategoryRule::factory()->for($stranger)->create([
        'match_values' => ['TESCO'], 'set_category' => 'groceries',
    ]);

    $this->actingAs($this->user)
        ->from(route('category-rules.index'))
        ->post(route('category-rules.apply-one', $rule))
        ->assertForbidden();
});

test('the rules page no longer offers an uncategorised-only pass', function () {
    $this->actingAs($this->user)
        ->get(route('category-rules.index'))
        ->assertInertia(fn ($page) => $page->missing('uncategorisedCount'));
});

test('a rule matches on any of the strings it looks for', function () {
    $rule = CategoryRule::factory()->for($this->user)->make([
        'match_field' => 'any',
        'match_type' => 'contains',
        'match_values' => ['TESCO', 'SAINSBURY', 'LIDL'],
    ]);

    $middle = Transaction::factory()->forAccount($this->monzo)->make([
        'name' => "SAINSBURY'S LOCAL", 'description' => null, 'merchant_name' => null,
    ]);
    $last = Transaction::factory()->forAccount($this->monzo)->make([
        'name' => 'LIDL GB BECKENHAM', 'description' => null, 'merchant_name' => null,
    ]);
    $neither = Transaction::factory()->forAccount($this->monzo)->make([
        'name' => 'CAFFE NERO', 'description' => null, 'merchant_name' => null,
    ]);

    expect($rule->matches($middle))->toBeTrue()
        ->and($rule->matches($last))->toBeTrue()
        ->and($rule->matches($neither))->toBeFalse();
});

/** Every string is read the same way, so the match type applies to all of them. */
test('the match type applies to every string a rule looks for', function () {
    $rule = CategoryRule::factory()->for($this->user)->make([
        'match_type' => 'starts_with',
        'match_values' => ['TESCO', 'LIDL'],
    ]);

    $starts = Transaction::factory()->forAccount($this->monzo)->make([
        'name' => 'LIDL GB BECKENHAM', 'description' => null, 'merchant_name' => null,
    ]);
    $contains = Transaction::factory()->forAccount($this->monzo)->make([
        'name' => 'PAYMENT TO LIDL GB', 'description' => null, 'merchant_name' => null,
    ]);

    expect($rule->matches($starts))->toBeTrue()
        ->and($rule->matches($contains))->toBeFalse();
});

test('a rule can be saved with several strings to look for', function () {
    $this->actingAs($this->user)
        ->post(route('category-rules.store'), [
            'name' => 'Supermarkets',
            'match_field' => 'any',
            'match_type' => 'contains',
            'match_values' => ['tesco', 'sainsbury', 'lidl'],
            'set_category' => 'groceries',
        ])
        ->assertSessionHasNoErrors();

    expect(CategoryRule::first()->match_values)->toBe(['tesco', 'sainsbury', 'lidl']);
});

/**
 * The form sends a box the user added and then left alone as an empty string,
 * which asks for nothing and so is dropped rather than reported.
 */
test('blank boxes and repeats are dropped when a rule is saved', function () {
    $this->actingAs($this->user)
        ->post(route('category-rules.store'), [
            'name' => 'Supermarkets',
            'match_field' => 'any',
            'match_type' => 'contains',
            'match_values' => ['  tesco  ', '', 'lidl', 'tesco', '   '],
            'set_category' => 'groceries',
        ])
        ->assertSessionHasNoErrors();

    expect(CategoryRule::first()->match_values)->toBe(['tesco', 'lidl']);
});

test('a rule with nothing to look for is rejected', function () {
    $this->actingAs($this->user)
        ->post(route('category-rules.store'), [
            'name' => 'Looks for nothing',
            'match_field' => 'any',
            'match_type' => 'contains',
            'match_values' => ['', '  '],
            'set_category' => 'groceries',
        ])
        ->assertSessionHasErrors('match_values');

    expect(CategoryRule::count())->toBe(0);
});

/** The error has to land on the box that holds the broken pattern. */
test('an invalid regular expression is reported against its own box', function () {
    $this->actingAs($this->user)
        ->post(route('category-rules.store'), [
            'name' => 'Broken',
            'match_field' => 'any',
            'match_type' => 'regex',
            'match_values' => ['^tesco', '([unclosed'],
            'set_category' => 'groceries',
        ])
        ->assertSessionHasErrors('match_values.1')
        ->assertSessionDoesntHaveErrors('match_values.0');
});

test('editing a rule can add a string to the ones it looks for', function () {
    $rule = CategoryRule::factory()->for($this->user)->create([
        'match_values' => ['tesco'],
        'set_category' => 'groceries',
    ]);

    $this->actingAs($this->user)
        ->patch(route('category-rules.update', $rule), [
            'name' => $rule->name,
            'match_field' => 'any',
            'match_type' => 'contains',
            'match_values' => ['tesco', 'lidl'],
            'set_category' => 'groceries',
        ])
        ->assertSessionHasNoErrors();

    expect($rule->fresh()->match_values)->toBe(['tesco', 'lidl']);
});

test('the rules listing carries every string a rule looks for', function () {
    CategoryRule::factory()->for($this->user)->create([
        'match_values' => ['tesco', 'lidl'],
    ]);

    $this->actingAs($this->user)
        ->get(route('category-rules.index'))
        ->assertInertia(fn ($page) => $page->where('rules.0.matchValues', ['tesco', 'lidl']));
});

/** A row matched by the second string is counted the same as one matched by the first. */
test('the match count covers every string a rule looks for', function () {
    $rule = CategoryRule::factory()->for($this->user)->create([
        'match_values' => ['TESCO', 'LIDL'],
    ]);

    foreach (['TESCO STORES', 'LIDL GB', 'CAFFE NERO'] as $name) {
        Transaction::factory()->forAccount($this->monzo)->create([
            'user_id' => $this->user->id,
            'name' => $name,
            'description' => null,
            'merchant_name' => null,
        ]);
    }

    $counts = collect($this->actingAs($this->user)
        ->get(route('category-rules.index'))
        ->viewData('page')['props']['rules'])
        ->pluck('matchCount', 'id');

    expect($counts[$rule->id])->toBe(2);
});
