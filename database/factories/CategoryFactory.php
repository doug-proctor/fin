<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
{
    protected $model = Category::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $value = fake()->unique()->slug(2, false);

        return [
            'user_id' => User::factory(),
            'value' => $value,
            'label' => ucfirst(str_replace('_', ' ', $value)),
        ];
    }

    /**
     * A category made in the Monzo app, which arrives as an opaque id.
     */
    public function custom(): static
    {
        return $this->state(fn (array $attributes): array => [
            'value' => 'category_'.fake()->unique()->regexify('[0-9A-Za-z]{22}'),
        ]);
    }
}
