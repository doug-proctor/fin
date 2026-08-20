<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\CategoryTarget;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CategoryTarget>
 */
class CategoryTargetFactory extends Factory
{
    protected $model = CategoryTarget::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'month' => now()->format('Y-m'),
            'category' => fake()->randomElement(array_keys(Category::DEFAULTS)),
            'amount_minor' => fake()->numberBetween(1000, 100000),
        ];
    }

    /**
     * Pin the target to one month, written the way the URL writes it.
     */
    public function forMonth(string $month): self
    {
        return $this->state(fn (): array => ['month' => $month]);
    }
}
