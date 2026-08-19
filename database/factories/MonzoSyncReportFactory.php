<?php

namespace Database\Factories;

use App\Models\MonzoSyncReport;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MonzoSyncReport>
 */
class MonzoSyncReportFactory extends Factory
{
    protected $model = MonzoSyncReport::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'status' => MonzoSyncReport::STATUS_COMPLETED,
            'imported' => 0,
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ];
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => MonzoSyncReport::STATUS_FAILED,
            'error' => 'Monzo returned 403: reauthentication required.',
        ]);
    }
}
