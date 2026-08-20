<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Transactions\TransactionFilters;
use Illuminate\Http\Request;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->monzo = Account::factory()->monzo()->for($this->user)->create(['name' => 'Monzo Current']);
    $this->amex = Account::factory()->amex()->for($this->user)->create(['name' => 'American Express']);
});

test('the transactions page requires a signed in user', function () {
    $this->get(route('transactions.index'))->assertRedirect(route('login'));
});

test('the transactions page renders', function () {
    $this->actingAs($this->user)->get(route('transactions.index'))->assertOk();
});

test('only the signed in user transactions are returned', function () {
    Transaction::factory()->forAccount($this->monzo)->count(3)->create();

    $other = User::factory()->create();
    $otherAccount = Account::factory()->monzo()->for($other)->create();
    Transaction::factory()->forAccount($otherAccount)->count(5)->create();

    expect(query($this->user)->summary()['count'])->toBe(3);
    expect(query($other)->summary()['count'])->toBe(5);
});

test('the summary totals money in and out across the whole filtered set', function () {
    Transaction::factory()->forAccount($this->monzo)->create(['amount_minor' => -1000]);
    Transaction::factory()->forAccount($this->monzo)->create(['amount_minor' => -2500]);
    Transaction::factory()->forAccount($this->monzo)->create(['amount_minor' => 150000]);

    $summary = query($this->user)->summary();

    expect($summary['count'])->toBe(3);
    expect($summary['moneyOut'])->toBe(3500);
    expect($summary['moneyIn'])->toBe(150000);
    expect($summary['net'])->toBe(146500);
});

test('a transfer keeps its place in the count but adds nothing to the money totals', function () {
    Transaction::factory()->forAccount($this->monzo)->create(['amount_minor' => -1000, 'category' => 'groceries']);
    Transaction::factory()->forAccount($this->monzo)->create(['amount_minor' => 5000, 'category' => null]);
    Transaction::factory()->forAccount($this->monzo)->transfer()->create(['amount_minor' => -20000]);
    Transaction::factory()->forAccount($this->monzo)->transfer()->create(['amount_minor' => 20000]);

    $summary = query($this->user)->summary();

    /** All four rows are still listed and still counted. */
    expect(query($this->user)->rows())->toHaveCount(4);
    expect($summary['count'])->toBe(4);

    expect($summary['moneyOut'])->toBe(1000);
    expect($summary['moneyIn'])->toBe(5000);
    expect($summary['net'])->toBe(4000);
});

test('a month of nothing but transfers totals to zero', function () {
    Transaction::factory()->forAccount($this->monzo)->transfer()->count(3)->create(['amount_minor' => -7500]);

    $summary = query($this->user)->summary();

    expect($summary)->toBe(['count' => 3, 'moneyIn' => 0, 'moneyOut' => 0, 'net' => 0]);
});

test('a row says whether the totals leave it out', function () {
    Transaction::factory()->forAccount($this->monzo)->transfer()->create();
    Transaction::factory()->forAccount($this->monzo)->create(['category' => 'groceries']);
    Transaction::factory()->forAccount($this->monzo)->create(['category' => null]);

    $rows = $this->actingAs($this->user)
        ->get(route('transactions.index'))
        ->viewData('page')['props']['transactions'];

    $excluded = collect($rows)->keyBy('category')->map(fn (array $row): bool => $row['excludedFromTotals']);

    expect($excluded['transfers'])->toBeTrue();
    expect($excluded['groceries'])->toBeFalse();
    expect($excluded[''])->toBeFalse();
});

test('a month is shown whole, with no row limit', function () {
    Transaction::factory()->forAccount($this->monzo)->count(60)->create(['amount_minor' => -100]);

    $query = query($this->user);

    expect($query->rows()->count())->toBe(60);
    expect($query->summary()['count'])->toBe(60);
    expect($query->summary()['moneyOut'])->toBe(6000);
});

test('an empty set totals to zero rather than null', function () {
    $summary = query($this->user)->summary();

    expect($summary)->toBe(['count' => 0, 'moneyIn' => 0, 'moneyOut' => 0, 'net' => 0]);
});

test('transactions can be filtered by account', function () {
    Transaction::factory()->forAccount($this->monzo)->count(2)->create();
    Transaction::factory()->forAccount($this->amex)->count(3)->create();

    expect(query($this->user, ['accounts' => [$this->amex->id]])->summary()['count'])->toBe(3);
    expect(query($this->user, ['accounts' => [$this->monzo->id, $this->amex->id]])->summary()['count'])->toBe(5);
});

test('transactions can be filtered by direction', function () {
    Transaction::factory()->forAccount($this->monzo)->count(2)->create(['amount_minor' => -500]);
    Transaction::factory()->forAccount($this->monzo)->create(['amount_minor' => 900]);

    expect(query($this->user, ['direction' => 'out'])->summary()['count'])->toBe(2);
    expect(query($this->user, ['direction' => 'in'])->summary()['count'])->toBe(1);
});

test('transactions can be filtered by category', function () {
    Transaction::factory()->forAccount($this->monzo)->count(2)->create(['category' => 'groceries']);
    Transaction::factory()->forAccount($this->monzo)->create(['category' => 'personal_care']);

    expect(query($this->user, ['categories' => ['groceries']])->summary()['count'])->toBe(2);
});

test('amount bounds compare the absolute value so they mean the same in both directions', function () {
    Transaction::factory()->forAccount($this->monzo)->create(['amount_minor' => -2000]);
    Transaction::factory()->forAccount($this->monzo)->create(['amount_minor' => 2000]);
    Transaction::factory()->forAccount($this->monzo)->create(['amount_minor' => -50]);

    $result = query($this->user, ['amount_min' => '10'])->summary();

    expect($result['count'])->toBe(2);
});

test('amount bounds are entered in pounds and matched in pence', function () {
    Transaction::factory()->forAccount($this->monzo)->create(['amount_minor' => -999]);
    Transaction::factory()->forAccount($this->monzo)->create(['amount_minor' => -1001]);

    expect(query($this->user, ['amount_max' => '10'])->summary()['count'])->toBe(1);
    expect(query($this->user, ['amount_min' => '10.01'])->summary()['count'])->toBe(1);
});

test('search covers name, description, merchant and notes', function () {
    Transaction::factory()->forAccount($this->monzo)->create(['name' => 'Waitrose', 'description' => 'X', 'merchant_name' => null, 'notes' => null]);
    Transaction::factory()->forAccount($this->monzo)->create(['name' => 'A', 'description' => 'WAITROSE LTD', 'merchant_name' => null, 'notes' => null]);
    Transaction::factory()->forAccount($this->monzo)->create(['name' => 'B', 'description' => 'Y', 'merchant_name' => 'Waitrose', 'notes' => null]);
    Transaction::factory()->forAccount($this->monzo)->create(['name' => 'C', 'description' => 'Z', 'merchant_name' => null, 'notes' => 'waitrose run']);
    Transaction::factory()->forAccount($this->monzo)->create(['name' => 'D', 'description' => 'Q', 'merchant_name' => null, 'notes' => null]);

    expect(query($this->user, ['search' => 'waitrose'])->summary()['count'])->toBe(4);
});

test('transactions can be filtered to the unprocessed ones only', function () {
    Transaction::factory()->forAccount($this->monzo)->count(2)->create();
    Transaction::factory()->forAccount($this->monzo)->processed()->count(3)->create();

    expect(query($this->user)->summary()['count'])->toBe(5);
    expect(query($this->user, ['unprocessed' => true])->summary()['count'])->toBe(2);

    /** The checkbox is off by default, which must not narrow anything. */
    expect(query($this->user, ['unprocessed' => false])->summary()['count'])->toBe(5);
});

/** The checkbox reaches the server as a string, so both spellings must read alike. */
test('the unprocessed filter reads a query string value', function () {
    Transaction::factory()->forAccount($this->monzo)->create();
    Transaction::factory()->forAccount($this->monzo)->processed()->create();

    expect(query($this->user, ['unprocessed' => 'true'])->summary()['count'])->toBe(1);
    expect(query($this->user, ['unprocessed' => 'false'])->summary()['count'])->toBe(2);
});

test('a row says whether it has been processed yet', function () {
    Transaction::factory()->forAccount($this->monzo)->create(['name' => 'Fresh']);
    Transaction::factory()->forAccount($this->monzo)->processed()->create(['name' => 'Reviewed']);

    $rows = $this->actingAs($this->user)
        ->get(route('transactions.index'))
        ->viewData('page')['props']['transactions'];

    $processed = collect($rows)->keyBy('name')->map(fn (array $row): bool => $row['processed']);

    expect($processed['Fresh'])->toBeFalse();
    expect($processed['Reviewed'])->toBeTrue();
});

test('transactions can be filtered by tag', function () {
    Transaction::factory()->forAccount($this->monzo)->create(['tags' => ['work', 'travel']]);
    Transaction::factory()->forAccount($this->monzo)->create(['tags' => ['personal']]);
    Transaction::factory()->forAccount($this->monzo)->create(['tags' => null]);

    expect(query($this->user, ['tags' => ['work']])->summary()['count'])->toBe(1);
    expect(query($this->user, ['tags' => ['work', 'personal']])->summary()['count'])->toBe(2);
});

test('transactions can be filtered by type', function () {
    /**
     * This is what takes pot moves and top ups out of the picture, in place of
     * the hidden spending flags Monzo used to supply.
     */
    Transaction::factory()->forAccount($this->monzo)->count(2)->create(['type' => 'card_payment']);
    Transaction::factory()->forAccount($this->monzo)->create(['type' => 'pot_transfer']);

    expect(query($this->user)->summary()['count'])->toBe(3);
    expect(query($this->user, ['types' => ['card_payment']])->summary()['count'])->toBe(2);
});

test('a date preset bounds the set', function () {
    Transaction::factory()->forAccount($this->monzo)->create(['booked_at' => now()->startOfMonth()->addDay()]);
    Transaction::factory()->forAccount($this->monzo)->create(['booked_at' => now()->subMonthNoOverflow()->startOfMonth()->addDay()]);
    Transaction::factory()->forAccount($this->monzo)->create(['booked_at' => now()->subYears(2)]);

    /**
     * The month being shown always applies, so a preset narrows within it
     * rather than replacing it.
     */
    expect(query($this->user, ['date_preset' => 'this_month'])->summary()['count'])->toBe(1);
    expect(query($this->user, ['date_preset' => 'all'])->summary()['count'])->toBe(1);

    $lastMonth = now()->subMonthNoOverflow()->format('Y-m');

    expect(query($this->user, ['month' => $lastMonth])->summary()['count'])->toBe(1);
    expect(query($this->user, ['month' => $lastMonth, 'date_preset' => 'this_month'])->summary()['count'])->toBe(0);
});

test('a custom date range bounds the set inclusively', function () {
    Transaction::factory()->forAccount($this->monzo)->create(['booked_at' => '2026-03-01 23:30:00']);
    Transaction::factory()->forAccount($this->monzo)->create(['booked_at' => '2026-03-15 12:00:00']);
    Transaction::factory()->forAccount($this->monzo)->create(['booked_at' => '2026-04-01 00:30:00']);

    $result = query($this->user, [
        'month' => '2026-03',
        'date_preset' => 'custom',
        'date_from' => '2026-03-01',
        'date_to' => '2026-03-31',
    ])->summary();

    expect($result['count'])->toBe(2);
});

test('sorting is restricted to known columns', function () {
    Transaction::factory()->forAccount($this->monzo)->create(['amount_minor' => -100, 'name' => 'B']);
    Transaction::factory()->forAccount($this->monzo)->create(['amount_minor' => -900, 'name' => 'A']);

    $byAmount = query($this->user, ['sort' => 'amount', 'sort_direction' => 'asc'])->rows();
    expect($byAmount->first()->amount_minor)->toBe(-900);

    $byName = query($this->user, ['sort' => 'name', 'sort_direction' => 'asc'])->rows();
    expect($byName->first()->name)->toBe('A');

    /** An unknown sort falls back to the default rather than reaching the database. */
    $filters = TransactionFilters::fromArray(['sort' => 'amount_minor; drop table transactions']);
    expect($filters->sort)->toBe('date');
});

test('the month defaults to the current one', function () {
    expect(TransactionFilters::fromArray([])->monthStart()->format('Y-m'))
        ->toBe(now()->format('Y-m'));

    /** The default is left out of the query string, as every default is. */
    expect(TransactionFilters::fromArray([])->toQuery())->not->toHaveKey('month');
    expect(TransactionFilters::fromArray(['month' => '2026-03'])->toQuery()['month'])->toBe('2026-03');
});

test('an unreadable month falls back to the current one rather than showing nothing', function () {
    foreach (['', 'nonsense', '2026', '2026-13-01', null] as $value) {
        expect(TransactionFilters::fromArray(['month' => $value])->monthStart()->format('Y-m'))
            ->toBe(now()->format('Y-m'));
    }
});

test('the arrows step one month at a time and stop at the current month', function () {
    $current = TransactionFilters::fromArray([]);

    expect($current->isCurrentMonth())->toBeTrue();
    /** Nothing exists after this month, so the forward arrow is disabled. */
    expect($current->nextMonth())->toBeNull();
    expect($current->previousMonth()->format('Y-m'))
        ->toBe(now()->subMonthNoOverflow()->format('Y-m'));

    $past = TransactionFilters::fromArray(['month' => '2026-03']);

    expect($past->isCurrentMonth())->toBeFalse();
    expect($past->nextMonth()->format('Y-m'))->toBe('2026-04');
    expect($past->previousMonth()->format('Y-m'))->toBe('2026-02');
});

test('only the chosen month is returned', function () {
    Transaction::factory()->forAccount($this->monzo)->count(2)->create(['booked_at' => '2026-03-15 10:00:00']);
    Transaction::factory()->forAccount($this->monzo)->create(['booked_at' => '2026-04-01 00:00:01']);
    Transaction::factory()->forAccount($this->monzo)->create(['booked_at' => '2026-02-28 23:59:59']);

    expect(query($this->user, ['month' => '2026-03'])->rows()->count())->toBe(2);
    expect(query($this->user, ['month' => '2026-04'])->rows()->count())->toBe(1);
});

test('the first and last moments of a month are included', function () {
    Transaction::factory()->forAccount($this->monzo)->create(['booked_at' => '2026-03-01 00:00:00']);
    Transaction::factory()->forAccount($this->monzo)->create(['booked_at' => '2026-03-31 23:59:59']);

    expect(query($this->user, ['month' => '2026-03'])->rows()->count())->toBe(2);
});

test('the page sends the month label and the arrows', function () {
    $this->actingAs($this->user)
        ->get(route('transactions.index', ['month' => '2026-03']))
        ->assertInertia(fn ($page) => $page
            ->where('month.label', 'March 2026')
            ->where('month.previous', '2026-02')
            ->where('month.next', '2026-04'));

    /** The current month has nothing after it, which disables the arrow. */
    $this->actingAs($this->user)
        ->get(route('transactions.index'))
        ->assertInertia(fn ($page) => $page
            ->where('month.label', now()->format('F Y'))
            ->where('month.next', null));
});

test('filters survive a round trip through the query string', function () {
    $filters = TransactionFilters::fromArray([
        'date_preset' => 'this_month',
        'direction' => 'out',
        'categories' => ['groceries'],
        'search' => 'tesco',
        'group_by' => 'month',
        'sort' => 'amount',
        'month' => '2026-03',
        'unprocessed' => true,
    ]);

    $restored = TransactionFilters::fromArray($filters->toQuery());

    expect($restored->datePreset)->toBe('this_month');
    expect($restored->direction)->toBe('out');
    expect($restored->categories)->toBe(['groceries']);
    expect($restored->search)->toBe('tesco');
    expect($restored->groupBy)->toBe('month');
    expect($restored->sort)->toBe('amount');
    expect($restored->monthStart()->format('Y-m'))->toBe('2026-03');
    expect($restored->unprocessed)->toBeTrue();
});

test('the page exposes the accounts and facets the filters need', function () {
    Transaction::factory()->forAccount($this->monzo)->create([
        'category' => 'groceries',
        'type' => 'card_payment',
        'tags' => ['work'],
    ]);

    $this->actingAs($this->user)
        ->get(route('transactions.index'))
        ->assertInertia(fn ($page) => $page
            ->component('transactions')
            ->has('accounts', 2)
            ->where('facets.categories', ['groceries'])
            ->where('facets.types', ['card_payment'])
            ->where('facets.tags', ['work'])
            ->has('transactions', 1));
});

test('filters and subtotals reach the browser as objects even when empty', function () {
    Transaction::factory()->forAccount($this->monzo)->create();

    $response = $this->actingAs($this->user)
        ->withHeader('X-Inertia', 'true')
        ->withHeader('X-Inertia-Version', (string) app(HandleInertiaRequests::class)
            ->version(Request::create(route('transactions.index'))))
        ->get(route('transactions.index'));

    $response->assertOk();

    /**
     * An empty PHP array encodes as a JSON array. If these went out as [],
     * reading a key off them in the browser would find an Array method
     * instead of undefined, which is what once left the sort direction
     * permanently stuck.
     */
    $json = $response->getContent();

    expect($json)->toContain('"filters":{}');
    expect($json)->not->toContain('"filters":[]');
    expect($json)->toContain('"subtotals":{}');
    expect($json)->not->toContain('"subtotals":[]');
});

test('sorting a column in each direction reverses the rows', function () {
    Transaction::factory()->forAccount($this->monzo)->create(['booked_at' => '2026-03-05 12:00:00', 'name' => 'Alpha']);
    Transaction::factory()->forAccount($this->monzo)->create(['booked_at' => '2026-03-20 12:00:00', 'name' => 'Delta']);

    $newestFirst = query($this->user, ['month' => '2026-03', 'sort' => 'date', 'sort_direction' => 'desc'])->rows();
    $oldestFirst = query($this->user, ['month' => '2026-03', 'sort' => 'date', 'sort_direction' => 'asc'])->rows();

    expect($newestFirst->first()->name)->toBe('Delta');
    expect($oldestFirst->first()->name)->toBe('Alpha');
});

test('the default view reports the sort it is actually using', function () {
    Transaction::factory()->forAccount($this->monzo)->create();

    /**
     * The table marks a column as active by comparing against these, so the
     * defaults have to survive the round trip rather than being stripped as
     * "nothing set".
     */
    $filters = TransactionFilters::fromArray([]);

    expect($filters->sort)->toBe('date');
    expect($filters->sortDirection)->toBe('desc');
});
