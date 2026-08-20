<?php

use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->account = Account::factory()->monzo()->for($this->user)->create(['name' => 'Monzo Current']);
});

/**
 * A charge booked in one month that belongs to another: the meal was eaten in
 * May, the friend who paid was settled up with in June.
 */
function travelled(Account $account, string $bookedAt, string $accountingDate, int $amountMinor = -4500): Transaction
{
    return Transaction::factory()->forAccount($account)->create([
        'booked_at' => $bookedAt,
        'accounting_date' => $accountingDate,
        'amount_minor' => $amountMinor,
        'category' => 'eating_out',
        'name' => 'Dinner',
    ]);
}

test('a row without an accounting date is unaffected', function () {
    Transaction::factory()->forAccount($this->account)->create([
        'booked_at' => '2026-05-15 19:00:00',
        'amount_minor' => -4500,
    ]);

    $may = query($this->user, ['month' => '2026-05']);

    expect($may->rows()->count())->toBe(1);
    expect($may->summary())->toBe(['count' => 1, 'moneyIn' => 0, 'moneyOut' => 4500, 'net' => -4500]);
    expect($may->monthRoleFor($may->rows()->first()))->toBeNull();

    expect(query($this->user, ['month' => '2026-06'])->rows())->toBeEmpty();
});

test('the month a row was booked in still lists it but counts none of it', function () {
    travelled($this->account, '2026-06-20 19:00:00', '2026-05-10');

    $june = query($this->user, ['month' => '2026-06']);

    /** Still on screen, so it can be found and corrected. */
    expect($june->rows()->count())->toBe(1);
    expect($june->summary())->toBe(['count' => 0, 'moneyIn' => 0, 'moneyOut' => 0, 'net' => 0]);
    expect($june->monthRoleFor($june->rows()->first()))->toBe('ghost');
});

test('the month of the accounting date counts it', function () {
    travelled($this->account, '2026-06-20 19:00:00', '2026-05-10');

    $may = query($this->user, ['month' => '2026-05']);

    expect($may->rows()->count())->toBe(1);
    expect($may->summary())->toBe(['count' => 1, 'moneyIn' => 0, 'moneyOut' => 4500, 'net' => -4500]);
    expect($may->monthRoleFor($may->rows()->first()))->toBe('arrival');
});

test('one row appears in both months without being duplicated', function () {
    $transaction = travelled($this->account, '2026-06-20 19:00:00', '2026-05-10');

    $may = query($this->user, ['month' => '2026-05'])->rows();
    $june = query($this->user, ['month' => '2026-06'])->rows();

    expect($may->pluck('id')->all())->toBe([$transaction->id]);
    expect($june->pluck('id')->all())->toBe([$transaction->id]);
    expect(Transaction::count())->toBe(1);
});

test('an accounting date inside the booked month neither doubles nor greys out', function () {
    travelled($this->account, '2026-06-10 19:00:00', '2026-06-25', -1000);

    $june = query($this->user, ['month' => '2026-06']);

    expect($june->rows()->count())->toBe(1);
    expect($june->summary())->toBe(['count' => 1, 'moneyIn' => 0, 'moneyOut' => 1000, 'net' => -1000]);
    /** Nothing to explain, so no grey row and no alien. */
    expect($june->monthRoleFor($june->rows()->first()))->toBeNull();

    foreach (['2026-05', '2026-07'] as $month) {
        expect(query($this->user, ['month' => $month])->rows())->toBeEmpty();
    }
});

test('an accounting date on the first day of a month counts in it', function () {
    travelled($this->account, '2026-05-20 19:00:00', '2026-06-01');

    expect(query($this->user, ['month' => '2026-06'])->summary()['moneyOut'])->toBe(4500);
});

test('an accounting date on the last day of a month counts in it', function () {
    travelled($this->account, '2026-07-05 19:00:00', '2026-06-30');

    expect(query($this->user, ['month' => '2026-06'])->summary()['moneyOut'])->toBe(4500);
});

/**
 * The date cast writes 'Y-m-d H:i:s' into the column, so the month bounds have
 * to hold for a bare 'Y-m-d' as well. SQLite compares both as text and would
 * otherwise drop one end of the month depending which shape it found.
 */
test('an accounting date stored without a time still lands in the right month', function () {
    $transaction = travelled($this->account, '2026-07-05 19:00:00', '2026-06-30');

    DB::table('transactions')->where('id', $transaction->id)->update(['accounting_date' => '2026-06-30']);

    expect(query($this->user, ['month' => '2026-06'])->summary()['moneyOut'])->toBe(4500);
    expect(query($this->user, ['month' => '2026-06'])->rows()->count())->toBe(1);
});

test('a row counting elsewhere leaves the rest of the month untouched', function () {
    Transaction::factory()->forAccount($this->account)->create([
        'booked_at' => '2026-06-02 10:00:00',
        'amount_minor' => -1000,
    ]);
    travelled($this->account, '2026-06-20 19:00:00', '2026-05-10');

    expect(query($this->user, ['month' => '2026-06'])->summary())
        ->toBe(['count' => 1, 'moneyIn' => 0, 'moneyOut' => 1000, 'net' => -1000]);
});

test('a transfer that travelled is left out of both months money', function () {
    Transaction::factory()->forAccount($this->account)->transfer()->create([
        'booked_at' => '2026-06-20 19:00:00',
        'accounting_date' => '2026-05-10',
        'amount_minor' => -40000,
    ]);

    /** Counted in the month it belongs to, but a transfer's money never counts. */
    expect(query($this->user, ['month' => '2026-05'])->summary())
        ->toBe(['count' => 1, 'moneyIn' => 0, 'moneyOut' => 0, 'net' => 0]);
    expect(query($this->user, ['month' => '2026-06'])->summary())
        ->toBe(['count' => 0, 'moneyIn' => 0, 'moneyOut' => 0, 'net' => 0]);
});

test('the row tells the browser which month it belongs to', function () {
    travelled($this->account, '2026-06-20 19:00:00', '2026-05-10');

    $this->actingAs($this->user)
        ->get(route('transactions.index', ['month' => '2026-06']))
        ->assertInertia(fn ($page) => $page
            ->where('transactions.0.timeTravel', 'ghost')
            ->where('transactions.0.accountingDate', '2026-05-10'));

    $this->actingAs($this->user)
        ->get(route('transactions.index', ['month' => '2026-05']))
        ->assertInertia(fn ($page) => $page
            ->where('transactions.0.timeTravel', 'arrival')
            ->where('transactions.0.accountingDate', '2026-05-10'));
});

test('the date a row reads as follows the month being viewed', function () {
    travelled($this->account, '2026-06-20 19:00:00', '2026-05-10');

    $june = query($this->user, ['month' => '2026-06']);
    $may = query($this->user, ['month' => '2026-05']);

    expect($june->displayDateFor($june->rows()->first())->toDateString())->toBe('2026-06-20');
    expect($may->displayDateFor($may->rows()->first())->toDateString())->toBe('2026-05-10');
});

test('a row that travelled sorts by the date it reads as', function () {
    travelled($this->account, '2026-06-20 19:00:00', '2026-05-10');
    Transaction::factory()->forAccount($this->account)->create([
        'booked_at' => '2026-05-02 10:00:00', 'name' => 'Earlier',
    ]);
    Transaction::factory()->forAccount($this->account)->create([
        'booked_at' => '2026-05-25 10:00:00', 'name' => 'Later',
    ]);

    $names = query($this->user, ['month' => '2026-05'])->rows()->pluck('name')->all();

    /** Newest first, with the arrival sitting on its accounting date. */
    expect($names)->toBe(['Later', 'Dinner', 'Earlier']);
});

test('the filters still apply to a row that travelled', function () {
    travelled($this->account, '2026-06-20 19:00:00', '2026-05-10');
    $other = Account::factory()->amex()->for($this->user)->create();

    expect(query($this->user, ['month' => '2026-05', 'categories' => ['groceries']])->rows())->toBeEmpty();
    expect(query($this->user, ['month' => '2026-05', 'accounts' => [$other->id]])->rows())->toBeEmpty();
    expect(query($this->user, ['month' => '2026-05', 'search' => 'Dinner'])->rows()->count())->toBe(1);
});

/**
 * The date range narrows what is on screen, so it reads the date on screen
 * rather than the booked date the row no longer shows here.
 */
test('a custom date range reads the date a row shows as', function () {
    travelled($this->account, '2026-06-20 19:00:00', '2026-05-10');

    $inRange = query($this->user, [
        'month' => '2026-05',
        'date_preset' => 'custom',
        'date_from' => '2026-05-01',
        'date_to' => '2026-05-15',
    ]);

    $outOfRange = query($this->user, [
        'month' => '2026-05',
        'date_preset' => 'custom',
        'date_from' => '2026-05-16',
        'date_to' => '2026-05-31',
    ]);

    expect($inRange->rows()->count())->toBe(1);
    expect($inRange->summary()['moneyOut'])->toBe(4500);
    expect($outOfRange->rows())->toBeEmpty();
});

test('another user cannot see a row that travelled into their month', function () {
    travelled($this->account, '2026-06-20 19:00:00', '2026-05-10');

    $other = User::factory()->create();

    expect(query($other, ['month' => '2026-05'])->rows())->toBeEmpty();
});
