<?php

namespace Database\Factories;

use App\Models\AmexSyncReport;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AmexSyncReport>
 */
class AmexSyncReportFactory extends Factory
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
            'account_id' => null,
            'filename' => 'activity.csv',
            'status' => 'completed',
            'rows_total' => 0,
            'rows_imported' => 0,
            'rows_updated' => 0,
            'rows_skipped' => 0,
            'started_at' => now(),
            'finished_at' => now(),
        ];
    }
}
