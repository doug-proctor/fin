<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Transaction>
 */
class TransactionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $merchant = fake()->company();

        return [
            'user_id' => User::factory(),
            'account_id' => Account::factory(),
            'external_id' => 'tx_'.Str::random(20),
            'dedupe_hash' => sha1(Str::random(40)),
            /**
             * Inside the current month, because the table shows one month at
             * a time and a row dated outside it would simply not appear.
             */
            'booked_at' => fake()->dateTimeBetween(now()->startOfMonth(), now()),
            'amount_minor' => -fake()->numberBetween(100, 25000),
            'currency' => 'GBP',
            'name' => $merchant,
            'description' => mb_strtoupper($merchant).' LONDON GBR',
            /**
             * A category whose money counts. Category::EXCLUDED_FROM_TOTALS
             * is held out on purpose: a factory row that landed on one at
             * random would silently drop out of every money assertion, so a
             * test that wants a transfer asks for one.
             */
            'category' => fake()->randomElement(array_diff(
                array_keys(Category::DEFAULTS),
                Category::EXCLUDED_FROM_TOTALS,
            )),
            'type' => 'card_payment',
            'merchant_name' => $merchant,
        ];
    }

    /**
     * A row the user has already reviewed and marked off. Imported rows start
     * unprocessed, so this is the state a test asks for to get the other one.
     */
    public function processed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'processed' => true,
        ]);
    }

    /**
     * A transfer between the user's own accounts. Filed under 'ignore', which
     * is the category every total leaves the value out of.
     */
    public function transfer(): static
    {
        return $this->state(fn (array $attributes): array => [
            'category' => 'ignore',
            'type' => 'faster_payment',
            'name' => 'Transfer',
        ]);
    }

    /**
     * Money coming in rather than going out. Left unfiled, because there is
     * no income category: money in is read off the sign of the amount.
     */
    public function income(): static
    {
        return $this->state(fn (array $attributes): array => [
            'amount_minor' => fake()->numberBetween(50000, 300000),
            'category' => null,
            'type' => 'faster_payment',
            'name' => 'Salary',
        ]);
    }

    /**
     * @param  array<int, string>  $fields
     */
    public function overridden(array $fields): static
    {
        return $this->state(fn (array $attributes): array => [
            'overrides' => array_fill_keys($fields, true),
        ]);
    }

    /**
     * An AMEX row, which has no provider transaction id.
     */
    public function amex(): static
    {
        return $this->state(fn (array $attributes): array => [
            'external_id' => null,
            'type' => 'card_payment',
        ]);
    }

    /**
     * Attach to an existing account, keeping the owning user consistent.
     */
    public function forAccount(Account $account): static
    {
        return $this->state(fn (array $attributes): array => [
            'account_id' => $account->id,
            'user_id' => $account->user_id,
        ]);
    }
}
