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
        'match_value' => 'CAFFE NERO',
        'set_category' => 'eating_out',
    ]);

    importFixture($this->amex, 'amex-no-reference.csv');

    $transaction = Transaction::where('description', 'CAFFE NERO')->first();

    expect($transaction->category)->toBe('eating_out');
    expect($transaction->categorised_by)->toBe('rule');
});

test('rules run in priority order and the first match can stop the rest', function () {
    CategoryRule::factory()->for($this->user)->create([
        'name' => 'catch all', 'priority' => 0, 'match_value' => 'TESCO',
        'set_category' => 'general', 'stops_processing' => true,
    ]);
    CategoryRule::factory()->for($this->user)->create([
        'name' => 'specific', 'priority' => 100, 'match_value' => 'TESCO',
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
        'priority' => 100, 'match_value' => 'TESCO', 'stops_processing' => false,
        'set_category' => 'groceries', 'set_tags' => null,
    ]);
    CategoryRule::factory()->for($this->user)->create([
        'priority' => 50, 'match_value' => 'TESCO', 'stops_processing' => true,
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
        'match_value' => 'TESCO', 'set_category' => 'groceries',
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
        'match_value' => 'SHELL',
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
        'match_value' => 'AMAZON',
        'amount_max_minor' => -10000,
        'set_category' => 'shopping',
    ]);

    $small = Transaction::factory()->forAccount($this->monzo)->make(['name' => 'AMAZON', 'amount_minor' => -500, 'category' => null]);
    $large = Transaction::factory()->forAccount($this->monzo)->make(['name' => 'AMAZON', 'amount_minor' => -20000, 'category' => null]);

    $rules = app(ApplyCategoryRules::class);
    $rules->handle($small);
    $rules->handle($large);

    expect($small->category)->toBeNull();
    expect($large->category)->toBe('shopping');
});

test('an exact amount narrows a rule', function () {
    CategoryRule::factory()->for($this->user)->create([
        'match_value' => 'NETFLIX',
        'amount_minor' => -1099,
        'set_category' => 'entertainment',
    ]);

    $exact = Transaction::factory()->forAccount($this->monzo)->make(['name' => 'NETFLIX', 'amount_minor' => -1099, 'category' => null]);
    $other = Transaction::factory()->forAccount($this->monzo)->make(['name' => 'NETFLIX', 'amount_minor' => -1599, 'category' => null]);

    /** The sign is part of the amount, so a refund of the same size is not a match. */
    $refund = Transaction::factory()->forAccount($this->monzo)->make(['name' => 'NETFLIX', 'amount_minor' => 1099, 'category' => null]);

    $rules = app(ApplyCategoryRules::class);
    $rules->handle($exact);
    $rules->handle($other);
    $rules->handle($refund);

    expect($exact->category)->toBe('entertainment');
    expect($other->category)->toBeNull();
    expect($refund->category)->toBeNull();
});

test('an exact date narrows a rule, whatever time of day it was booked', function () {
    CategoryRule::factory()->for($this->user)->create([
        'match_value' => 'RENT',
        'booked_on' => '2026-03-01',
        'set_category' => 'bills',
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

    expect($onTheDay->category)->toBe('bills');
    expect($dayAfter->category)->toBeNull();
});

/** Both conditions narrow the same rule, so both have to hold. */
test('the exact amount and date conditions combine', function () {
    CategoryRule::factory()->for($this->user)->create([
        'match_value' => 'RENT',
        'amount_minor' => -95000,
        'booked_on' => '2026-03-01',
        'set_category' => 'bills',
    ]);

    $both = Transaction::factory()->forAccount($this->monzo)->make([
        'name' => 'RENT', 'amount_minor' => -95000, 'booked_at' => '2026-03-01 09:00:00', 'category' => null,
    ]);
    $rightDateWrongAmount = Transaction::factory()->forAccount($this->monzo)->make([
        'name' => 'RENT', 'amount_minor' => -80000, 'booked_at' => '2026-03-01 09:00:00', 'category' => null,
    ]);
    $rightAmountWrongDate = Transaction::factory()->forAccount($this->monzo)->make([
        'name' => 'RENT', 'amount_minor' => -95000, 'booked_at' => '2026-04-01 09:00:00', 'category' => null,
    ]);

    $rules = app(ApplyCategoryRules::class);
    $rules->handle($both);
    $rules->handle($rightDateWrongAmount);
    $rules->handle($rightAmountWrongDate);

    expect($both->category)->toBe('bills');
    expect($rightDateWrongAmount->category)->toBeNull();
    expect($rightAmountWrongDate->category)->toBeNull();
});

/** Left null they must not narrow anything, or every existing rule breaks. */
test('a rule with neither condition set still matches on text alone', function () {
    CategoryRule::factory()->for($this->user)->create([
        'match_value' => 'RENT',
        'amount_minor' => null,
        'booked_on' => null,
        'set_category' => 'bills',
    ]);

    $transaction = Transaction::factory()->forAccount($this->monzo)->make(['name' => 'RENT', 'category' => null]);

    app(ApplyCategoryRules::class)->handle($transaction);

    expect($transaction->category)->toBe('bills');
});

test('the exact conditions can be saved from the rules form', function () {
    $this->actingAs($this->user)
        ->from(route('category-rules.index'))
        ->post(route('category-rules.store'), [
            'name' => 'March rent',
            'match_field' => 'any',
            'match_type' => 'contains',
            'match_value' => 'RENT',
            'amount_minor' => -95000,
            'booked_on' => '2026-03-01',
            'set_category' => 'bills',
        ])
        ->assertSessionHasNoErrors();

    $rule = CategoryRule::query()->where('name', 'March rent')->sole();

    expect($rule->amount_minor)->toBe(-95000);
    expect($rule->booked_on->toDateString())->toBe('2026-03-01');
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
            'match_value' => 'RENT',
            'amount_minor' => null,
            'booked_on' => null,
            'set_category' => 'bills',
        ])
        ->assertSessionHasNoErrors();

    $rule = CategoryRule::query()->where('name', 'Any rent')->sole();

    expect($rule->amount_minor)->toBeNull();
    expect($rule->booked_on)->toBeNull();
});

test('amount bounds can be saved from the rules form', function () {
    $this->actingAs($this->user)
        ->from(route('category-rules.index'))
        ->post(route('category-rules.store'), [
            'name' => 'Big spends',
            'match_field' => 'any',
            'match_type' => 'contains',
            'match_value' => 'AMAZON',
            'amount_min_minor' => -5000,
            'amount_max_minor' => -1000,
            'set_category' => 'shopping',
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
            'match_value' => 'AMAZON',
            'amount_minor' => -1500,
            'amount_min_minor' => -5000,
            'set_category' => 'shopping',
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
            'match_value' => 'AMAZON',
            'amount_min_minor' => -1000,
            'amount_max_minor' => -5000,
            'set_category' => 'shopping',
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
            'match_value' => 'AMAZON',
            'amount_min_minor' => 0,
            'set_category' => 'shopping',
        ])
        ->assertSessionHasNoErrors();

    expect(CategoryRule::query()->where('name', 'Refunds and free')->sole()->amount_min_minor)->toBe(0);
});

test('the rules listing carries the exact conditions', function () {
    CategoryRule::factory()->for($this->user)->create([
        'name' => 'March rent',
        'amount_minor' => -95000,
        'booked_on' => '2026-03-01',
    ]);

    $this->actingAs($this->user)
        ->get(route('category-rules.index'))
        ->assertInertia(fn ($page) => $page
            ->where('rules.0.amountMinor', -95000)
            ->where('rules.0.bookedOn', '2026-03-01')
            ->has('rules.0.amountMinMinor')
            ->has('rules.0.amountMaxMinor')
        );
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
            'match_field' => 'any', 'match_type' => $type, 'match_value' => $value,
        ]);

        expect($rule->matches($transaction))->toBe($expected, "{$type} '{$value}'");
    }
});

test('a malformed regular expression does not blow up an import', function () {
    $rule = CategoryRule::factory()->for($this->user)->make([
        'match_type' => 'regex', 'match_value' => '([unclosed',
    ]);

    $transaction = Transaction::factory()->forAccount($this->monzo)->make(['name' => 'anything']);

    expect($rule->matches($transaction))->toBeFalse();
});

test('re-applying rules never overwrites a category set by hand', function () {
    $byHand = Transaction::factory()->forAccount($this->monzo)->create([
        'name' => 'TESCO STORES',
        'category' => 'bills',
        'categorised_by' => 'user',
        'overrides' => ['category' => true],
    ]);
    $untouched = Transaction::factory()->forAccount($this->monzo)->create([
        'name' => 'TESCO STORES', 'category' => null, 'categorised_by' => null,
    ]);

    CategoryRule::factory()->for($this->user)->create([
        'match_value' => 'TESCO', 'set_category' => 'groceries',
    ]);

    $this->actingAs($this->user)
        ->from(route('category-rules.index'))
        ->post(route('category-rules.apply'), ['only_uncategorised' => false])
        ->assertSessionHasNoErrors();

    expect($byHand->fresh()->category)->toBe('bills');
    expect($untouched->fresh()->category)->toBe('groceries');
});

test('rules give amex and monzo rows the same category', function () {
    CategoryRule::factory()->for($this->user)->create([
        'match_value' => 'TESCO', 'set_category' => 'groceries',
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
            'match_value' => 'tesco',
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
            'match_value' => 'tesco',
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
            'match_value' => 'tesco',
        ])
        ->assertSessionHasErrors('set_category');
});

test('an invalid regular expression is rejected at the form', function () {
    $this->actingAs($this->user)
        ->post(route('category-rules.store'), [
            'name' => 'Broken',
            'match_field' => 'any',
            'match_type' => 'regex',
            'match_value' => '([unclosed',
            'set_category' => 'groceries',
        ])
        ->assertSessionHasErrors('match_value');
});

test('you cannot edit or delete another user rule', function () {
    $other = User::factory()->create();
    $rule = CategoryRule::factory()->for($other)->create(['name' => 'Theirs']);

    $this->actingAs($this->user)
        ->patch(route('category-rules.update', $rule), [
            'name' => 'Mine', 'match_field' => 'any', 'match_type' => 'contains',
            'match_value' => 'x', 'set_category' => 'groceries',
        ])
        ->assertForbidden();

    $this->actingAs($this->user)
        ->delete(route('category-rules.destroy', $rule))
        ->assertForbidden();

    expect($rule->fresh()->name)->toBe('Theirs');
});

test('the rules page reports how many transactions a re-apply would change', function () {
    /** Two the rules may change... */
    Transaction::factory()->forAccount($this->monzo)->create(['categorised_by' => 'source']);
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
        'category' => 'general',
        'categorised_by' => 'source',
    ]);
    Transaction::factory()->forAccount($this->monzo)->create([
        'name' => 'TESCO STORES',
        'category' => 'bills',
        'categorised_by' => 'user',
    ]);

    CategoryRule::factory()->for($this->user)->create([
        'match_value' => 'TESCO', 'set_category' => 'groceries',
    ]);

    $this->actingAs($this->user)
        ->get(route('category-rules.index'))
        ->assertInertia(fn ($page) => $page->where('recategorisableCount', 3));

    $this->actingAs($this->user)
        ->from(route('category-rules.index'))
        ->post(route('category-rules.apply'), ['only_uncategorised' => false]);

    expect(Transaction::where('category', 'groceries')->count())->toBe(3);
    expect(Transaction::where('categorised_by', 'user')->first()->category)
        ->toBe('bills');
});

test('deleting a rule leaves the categories it already applied alone', function () {
    $rule = CategoryRule::factory()->for($this->user)->create([
        'match_value' => 'TESCO', 'set_category' => 'groceries',
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
