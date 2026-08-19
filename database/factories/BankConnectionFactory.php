<?php

namespace Database\Factories;

use App\Models\BankConnection;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<BankConnection>
 */
class BankConnectionFactory extends Factory
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
            'provider' => 'monzo',
            'external_user_id' => 'user_'.Str::random(20),
            'access_token' => 'access_'.Str::random(32),
            'refresh_token' => 'refresh_'.Str::random(32),
            'expires_at' => now()->addHours(6),
            'authorised_at' => now(),
            'sca_confirmed_at' => now(),
        ];
    }

    /**
     * A connection that has been redirected but not yet had its code
     * exchanged for tokens.
     */
    public function pendingAuthorisation(): static
    {
        return $this->state(fn (array $attributes): array => [
            'access_token' => null,
            'refresh_token' => null,
            'expires_at' => null,
            'oauth_state' => Str::random(40),
            'authorised_at' => null,
            'sca_confirmed_at' => null,
        ]);
    }

    /**
     * Tokens are stored but the user has not yet approved the push
     * notification in their bank app, so the API still answers 403.
     */
    public function pendingSca(): static
    {
        return $this->state(fn (array $attributes): array => [
            'sca_confirmed_at' => null,
        ]);
    }

    /**
     * The five minute full-history window was missed.
     */
    public function expired(): static
    {
        return $this->state(fn (array $attributes): array => [
            'expires_at' => now()->subHour(),
        ]);
    }

    public function revoked(): static
    {
        return $this->state(fn (array $attributes): array => [
            'revoked_at' => now(),
        ]);
    }
}
