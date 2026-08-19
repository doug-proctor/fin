<?php

use App\Models\Transaction;

test('money in and money out derive from the signed amount', function () {
    $spend = new Transaction(['amount_minor' => -1250]);
    $income = new Transaction(['amount_minor' => 250000]);

    expect($spend->money_out_minor)->toBe(1250);
    expect($spend->money_in_minor)->toBe(0);
    expect($income->money_in_minor)->toBe(250000);
    expect($income->money_out_minor)->toBe(0);
});

test('overridden fields are stripped from an incoming import payload', function () {
    $transaction = new Transaction(['overrides' => ['category' => true, 'notes' => true]]);

    $writable = $transaction->withoutOverridden([
        'name' => 'Tesco',
        'category' => 'groceries',
        'notes' => 'from the bank',
        'amount_minor' => -500,
    ]);

    expect($writable)->toBe(['name' => 'Tesco', 'amount_minor' => -500]);
});

test('marking fields overridden preserves existing overrides', function () {
    $transaction = new Transaction(['overrides' => ['notes' => true]]);

    $transaction->markOverridden(['category', 'name']);

    expect($transaction->overrides)->toBe(['notes' => true, 'category' => true, 'name' => true]);
    expect($transaction->isOverridden('category'))->toBeTrue();
    expect($transaction->isOverridden('amount_minor'))->toBeFalse();
});
