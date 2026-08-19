<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\BankConnection;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Account>
 */
class AccountFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'bank_connection_id' => null,
            'provider' => 'monzo',
            'external_id' => 'acc_'.Str::random(20),
            'name' => 'Monzo',
            'currency' => 'GBP',
        ];
    }

    /**
     * The Monzo current account, hung off a real connection.
     */
    public function monzo(): static
    {
        return $this->state(fn (array $attributes): array => [
            'provider' => 'monzo',
            'name' => 'Monzo',
            'bank_connection_id' => BankConnection::factory(),
        ]);
    }

    /**
     * The AMEX card, which has no OAuth connection because its rows arrive
     * by CSV upload.
     */
    public function amex(): static
    {
        return $this->state(fn (array $attributes): array => [
            'provider' => 'amex',
            'name' => 'Amex',
            'external_id' => null,
            'bank_connection_id' => null,
        ]);
    }
}
