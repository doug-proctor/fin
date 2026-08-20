<?php

use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->account = Account::factory()->monzo()->for($this->user)->create();
});

test('marking a month off requires a signed in user', function () {
    $this->post(route('transactions.mark-processed'))->assertRedirect(route('login'));
});

test('every unprocessed row in the month being shown is marked off', function () {
    $rows = Transaction::factory()
        ->forAccount($this->account)
        ->count(3)
        ->create(['booked_at' => now()->startOfMonth()->addDays(2)]);

    $this->actingAs($this->user)
        ->from(route('transactions.index'))
        ->post(route('transactions.mark-processed'), ['month' => now()->format('Y-m')])
        ->assertRedirect(route('transactions.index'));

    foreach ($rows as $row) {
        expect($row->refresh()->processed)->toBeTrue();
    }
});

/** The whole point of the confirmation is that it says which month. */
test('rows in another month are left alone', function () {
    $thisMonth = Transaction::factory()
        ->forAccount($this->account)
        ->create(['booked_at' => now()->startOfMonth()->addDay()]);

    $lastMonth = Transaction::factory()
        ->forAccount($this->account)
        ->create(['booked_at' => now()->startOfMonth()->subMonthNoOverflow()->addDay()]);

    $this->actingAs($this->user)
        ->post(route('transactions.mark-processed'), ['month' => now()->format('Y-m')]);

    expect($thisMonth->refresh()->processed)->toBeTrue();
    expect($lastMonth->refresh()->processed)->toBeFalse();
});

test('a past month can be marked off without touching the current one', function () {
    $month = now()->startOfMonth()->subMonthNoOverflow();

    $past = Transaction::factory()
        ->forAccount($this->account)
        ->create(['booked_at' => $month->copy()->addDay()]);

    $current = Transaction::factory()
        ->forAccount($this->account)
        ->create(['booked_at' => now()->startOfMonth()->addDay()]);

    $this->actingAs($this->user)
        ->post(route('transactions.mark-processed'), ['month' => $month->format('Y-m')]);

    expect($past->refresh()->processed)->toBeTrue();
    expect($current->refresh()->processed)->toBeFalse();
});

test('another user rows are never touched', function () {
    $other = User::factory()->create();
    $otherAccount = Account::factory()->monzo()->for($other)->create();
    $theirs = Transaction::factory()->forAccount($otherAccount)->create();

    $this->actingAs($this->user)
        ->post(route('transactions.mark-processed'), ['month' => now()->format('Y-m')]);

    expect($theirs->refresh()->processed)->toBeFalse();
});

/**
 * The filter bar narrows the table, not the write. The button says "every
 * transaction in August 2026", so a filter left switched on must not quietly
 * shrink what it marks off.
 */
test('the filters on the table do not narrow what is marked off', function () {
    $amex = Account::factory()->amex()->for($this->user)->create();

    $monzoRow = Transaction::factory()->forAccount($this->account)->create();
    $amexRow = Transaction::factory()->forAccount($amex)->create();

    $this->actingAs($this->user)->post(route('transactions.mark-processed'), [
        'month' => now()->format('Y-m'),
        'accounts' => [$this->account->id],
        'search' => 'nothing matches this',
        'unprocessed' => true,
    ]);

    expect($monzoRow->refresh()->processed)->toBeTrue();
    expect($amexRow->refresh()->processed)->toBeTrue();
});

test('an unreadable month falls back to the current one rather than marking nothing', function () {
    $row = Transaction::factory()->forAccount($this->account)->create();

    $this->actingAs($this->user)
        ->post(route('transactions.mark-processed'), ['month' => 'not-a-month']);

    expect($row->refresh()->processed)->toBeTrue();
});

test('the toast counts what was marked', function () {
    Transaction::factory()->forAccount($this->account)->count(2)->create();

    $this->actingAs($this->user)
        ->post(route('transactions.mark-processed'), ['month' => now()->format('Y-m')])
        ->assertInertiaFlash('toast.type', 'success')
        ->assertInertiaFlash(
            'toast.message',
            '2 transactions in '.now()->format('F Y').' marked as processed.',
        );
});

test('marking a month with nothing left says so rather than reporting a count', function () {
    Transaction::factory()->processed()->forAccount($this->account)->create();

    $this->actingAs($this->user)
        ->post(route('transactions.mark-processed'), ['month' => now()->format('Y-m')])
        ->assertInertiaFlash(
            'toast.message',
            'Nothing left to mark in '.now()->format('F Y').'.',
        );
});

/** The button is disabled on zero, so the count has to ignore the filters too. */
test('the page reports how many rows are left to mark in the month', function () {
    Transaction::factory()->forAccount($this->account)->count(2)->create();
    Transaction::factory()->processed()->forAccount($this->account)->create();
    Transaction::factory()
        ->forAccount($this->account)
        ->create(['booked_at' => now()->startOfMonth()->subMonthNoOverflow()->addDay()]);

    $this->actingAs($this->user)
        ->get(route('transactions.index', ['search' => 'nothing matches this']))
        ->assertInertia(fn ($page) => $page
            ->where('unprocessedCount', 2)
            ->where('month.current', now()->format('Y-m')));
});
