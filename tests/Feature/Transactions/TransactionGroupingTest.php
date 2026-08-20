<?php

use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->monzo = Account::factory()->monzo()->for($this->user)->create(['name' => 'Monzo Current']);
    $this->amex = Account::factory()->amex()->for($this->user)->create(['name' => 'American Express']);
});

test('no subtotals are produced when grouping is off', function () {
    Transaction::factory()->forAccount($this->monzo)->count(3)->create();

    expect(query($this->user)->groupSubtotals())->toBe([]);
});

test('grouping by day totals each day', function () {
    Transaction::factory()->forAccount($this->monzo)->create(['booked_at' => '2026-03-05 10:00:00', 'amount_minor' => -1000]);
    Transaction::factory()->forAccount($this->monzo)->create(['booked_at' => '2026-03-05 18:00:00', 'amount_minor' => -2000]);
    Transaction::factory()->forAccount($this->monzo)->create(['booked_at' => '2026-03-20 10:00:00', 'amount_minor' => 50000]);

    $subtotals = query($this->user, ['group_by' => 'day', 'month' => '2026-03'])->groupSubtotals();

    expect($subtotals)->toHaveKeys(['2026-03-05', '2026-03-20']);
    expect($subtotals['2026-03-05'])->toBe(['count' => 2, 'moneyIn' => 0, 'moneyOut' => 3000, 'net' => -3000]);
    expect($subtotals['2026-03-20'])->toBe(['count' => 1, 'moneyIn' => 50000, 'moneyOut' => 0, 'net' => 50000]);
});

test('grouping by category totals each category', function () {
    Transaction::factory()->forAccount($this->monzo)->count(2)->create([
        'category' => 'groceries', 'amount_minor' => -1500,
    ]);
    Transaction::factory()->forAccount($this->monzo)->create([
        'category' => 'bills', 'amount_minor' => -8000,
    ]);

    $subtotals = query($this->user, ['group_by' => 'category'])->groupSubtotals();

    expect($subtotals['groceries']['count'])->toBe(2);
    expect($subtotals['groceries']['moneyOut'])->toBe(3000);
    expect($subtotals['bills']['moneyOut'])->toBe(8000);
});

test('a transfer adds nothing to its group subtotal, so the subtotals still add up to the month', function () {
    Transaction::factory()->forAccount($this->monzo)->create([
        'booked_at' => '2026-03-05 10:00:00', 'category' => 'groceries', 'amount_minor' => -1500,
    ]);
    Transaction::factory()->forAccount($this->monzo)->transfer()->create([
        'booked_at' => '2026-03-05 12:00:00', 'amount_minor' => -40000,
    ]);

    $subtotals = query($this->user, ['group_by' => 'day', 'month' => '2026-03'])->groupSubtotals();

    /** Both rows are in the group; only the grocery shop is in its money. */
    expect($subtotals['2026-03-05'])->toBe(['count' => 2, 'moneyIn' => 0, 'moneyOut' => 1500, 'net' => -1500]);
});

test('grouping by category gives the transfers group a count but no money', function () {
    Transaction::factory()->forAccount($this->monzo)->transfer()->count(2)->create([
        'amount_minor' => -6000,
    ]);
    Transaction::factory()->forAccount($this->monzo)->create([
        'category' => 'bills', 'amount_minor' => -8000,
    ]);

    $subtotals = query($this->user, ['group_by' => 'category'])->groupSubtotals();

    expect($subtotals['transfers'])->toBe(['count' => 2, 'moneyIn' => 0, 'moneyOut' => 0, 'net' => 0]);
    expect($subtotals['bills']['moneyOut'])->toBe(8000);
});

test('grouping by account keys on the account id', function () {
    Transaction::factory()->forAccount($this->monzo)->count(2)->create(['amount_minor' => -100]);
    Transaction::factory()->forAccount($this->amex)->count(3)->create(['amount_minor' => -200]);

    $subtotals = query($this->user, ['group_by' => 'account'])->groupSubtotals();

    expect($subtotals[(string) $this->monzo->id]['count'])->toBe(2);
    expect($subtotals[(string) $this->amex->id]['count'])->toBe(3);
    expect($subtotals[(string) $this->amex->id]['moneyOut'])->toBe(600);
});

test('grouping by merchant falls back to the name when no merchant is known', function () {
    Transaction::factory()->forAccount($this->monzo)->create(['merchant_name' => 'Tesco', 'name' => 'Tesco']);
    Transaction::factory()->forAccount($this->monzo)->create(['merchant_name' => null, 'name' => 'Rent']);

    $subtotals = query($this->user, ['group_by' => 'merchant'])->groupSubtotals();

    expect($subtotals)->toHaveKeys(['Tesco', 'Rent']);
});

test('an uncategorised row groups under an empty key rather than being dropped', function () {
    Transaction::factory()->forAccount($this->monzo)->create(['category' => null, 'amount_minor' => -700]);

    $subtotals = query($this->user, ['group_by' => 'category'])->groupSubtotals();

    expect($subtotals[''])->toBe(['count' => 1, 'moneyIn' => 0, 'moneyOut' => 700, 'net' => -700]);
});

test('the group key computed in php matches the one computed in sql', function () {
    /** Dates chosen to straddle an ISO week boundary inside one month. */
    Transaction::factory()->forAccount($this->monzo)->create(['booked_at' => '2025-12-28 10:00:00']);
    Transaction::factory()->forAccount($this->monzo)->create(['booked_at' => '2025-12-29 10:00:00']);
    Transaction::factory()->forAccount($this->monzo)->create(['booked_at' => '2025-12-31 10:00:00']);

    foreach (['day', 'week', 'month', 'category', 'account', 'merchant'] as $groupBy) {
        $query = query($this->user, ['group_by' => $groupBy, 'month' => '2025-12']);
        $subtotals = $query->groupSubtotals();

        foreach ($query->rows() as $transaction) {
            $key = $query->groupKeyFor($transaction);

            expect(array_key_exists($key, $subtotals))->toBeTrue(
                "PHP produced group key '{$key}' for {$groupBy}, which SQL did not.",
            );
        }
    }
});

test('the group key computed in php matches the one computed in sql for a row that travelled', function () {
    /** Straddling a month boundary and an ISO week boundary at once. */
    Transaction::factory()->forAccount($this->monzo)->create([
        'booked_at' => '2025-12-31 10:00:00', 'accounting_date' => '2026-01-02',
    ]);
    Transaction::factory()->forAccount($this->monzo)->create([
        'booked_at' => '2026-01-02 10:00:00', 'accounting_date' => '2025-12-31',
    ]);

    foreach (['2025-12', '2026-01'] as $month) {
        foreach (['day', 'week', 'month'] as $groupBy) {
            $query = query($this->user, ['group_by' => $groupBy, 'month' => $month]);
            $subtotals = $query->groupSubtotals();

            foreach ($query->rows() as $transaction) {
                $key = $query->groupKeyFor($transaction);

                expect(array_key_exists($key, $subtotals))->toBeTrue(
                    "PHP produced group key '{$key}' for {$groupBy} in {$month}, which SQL did not.",
                );
            }
        }
    }
});

test('a row groups under the accounting date in the month it counts towards', function () {
    Transaction::factory()->forAccount($this->monzo)->create([
        'booked_at' => '2026-05-28 10:00:00',
        'accounting_date' => '2026-06-03',
        'amount_minor' => -4500,
    ]);

    $subtotals = query($this->user, ['group_by' => 'day', 'month' => '2026-06'])->groupSubtotals();

    expect($subtotals)->toHaveKey('2026-06-03');
    expect($subtotals)->not->toHaveKey('2026-05-28');
    expect($subtotals['2026-06-03'])->toBe(['count' => 1, 'moneyIn' => 0, 'moneyOut' => 4500, 'net' => -4500]);
});

test('a row still heads its booked day in the month it left, with nothing under it', function () {
    Transaction::factory()->forAccount($this->monzo)->create([
        'booked_at' => '2026-05-28 10:00:00',
        'accounting_date' => '2026-06-03',
        'amount_minor' => -4500,
    ]);

    $subtotals = query($this->user, ['group_by' => 'day', 'month' => '2026-05'])->groupSubtotals();

    /**
     * The group survives so the header has something to print; it just adds
     * up to nothing, which is what the greyed out row is saying.
     */
    expect($subtotals['2026-05-28'])->toBe(['count' => 0, 'moneyIn' => 0, 'moneyOut' => 0, 'net' => 0]);
});

test('grouped rows stay contiguous when one of them travelled in', function () {
    Transaction::factory()->forAccount($this->monzo)->create(['booked_at' => '2026-06-05 10:00:00']);
    Transaction::factory()->forAccount($this->monzo)->create(['booked_at' => '2026-06-25 10:00:00']);
    Transaction::factory()->forAccount($this->monzo)->create([
        'booked_at' => '2026-05-30 10:00:00', 'accounting_date' => '2026-06-05',
    ]);

    $query = query($this->user, ['group_by' => 'day', 'month' => '2026-06']);

    $keys = $query->rows()
        ->map(fn (Transaction $transaction): string => $query->groupKeyFor($transaction))
        ->all();

    expect($keys)->toBe(['2026-06-25', '2026-06-05', '2026-06-05']);
});

test('a month is never truncated, however many transactions it holds', function () {
    Transaction::factory()->forAccount($this->monzo)->count(30)->create([
        'booked_at' => '2026-03-15 10:00:00', 'amount_minor' => -100,
    ]);

    $query = query($this->user, ['group_by' => 'day', 'month' => '2026-03']);

    /** The month is the only limit, so all 30 rows come back. */
    expect($query->rows()->count())->toBe(30);

    expect($query->groupSubtotals()['2026-03-15'])->toBe([
        'count' => 30, 'moneyIn' => 0, 'moneyOut' => 3000, 'net' => -3000,
    ]);
});

test('subtotals respect the active filters', function () {
    Transaction::factory()->forAccount($this->monzo)->create(['booked_at' => '2026-03-05 10:00:00', 'amount_minor' => -1000]);
    Transaction::factory()->forAccount($this->amex)->create(['booked_at' => '2026-03-06 10:00:00', 'amount_minor' => -9999]);

    $subtotals = query($this->user, [
        'group_by' => 'day',
        'month' => '2026-03',
        'accounts' => [$this->monzo->id],
    ])->groupSubtotals();

    expect($subtotals['2026-03-05']['count'])->toBe(1);
    expect($subtotals['2026-03-05']['moneyOut'])->toBe(1000);
});

test('grouped rows arrive ordered by their group so headers can be inserted in one pass', function () {
    Transaction::factory()->forAccount($this->monzo)->create(['booked_at' => '2026-03-05 10:00:00']);
    Transaction::factory()->forAccount($this->monzo)->create(['booked_at' => '2026-03-25 10:00:00']);
    Transaction::factory()->forAccount($this->monzo)->create(['booked_at' => '2026-03-05 18:00:00']);
    Transaction::factory()->forAccount($this->monzo)->create(['booked_at' => '2026-03-25 18:00:00']);

    $query = query($this->user, ['group_by' => 'day', 'month' => '2026-03']);

    $keys = $query->rows()
        ->map(fn (Transaction $transaction): string => $query->groupKeyFor($transaction))
        ->all();

    /** Each group appears exactly once as a contiguous run. */
    expect($keys)->toBe(['2026-03-25', '2026-03-25', '2026-03-05', '2026-03-05']);
    expect(array_unique($keys))->toHaveCount(count(array_unique($keys)));
});

test('the page exposes subtotals when grouping is requested', function () {
    Transaction::factory()->forAccount($this->monzo)->create([
        'booked_at' => '2026-03-05 10:00:00', 'amount_minor' => -1000,
    ]);

    $this->actingAs($this->user)
        ->get(route('transactions.index', ['group_by' => 'day', 'month' => '2026-03']))
        ->assertInertia(fn ($page) => $page
            ->component('transactions')
            ->where('subtotals.2026-03-05.moneyOut', 1000)
            ->where('filters.group_by', 'day'));
});
