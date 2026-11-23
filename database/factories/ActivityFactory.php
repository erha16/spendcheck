<?php

namespace Database\Factories;

use App\Models\Activity;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Activity>
 */
class ActivityFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->bothify('ACT-####')),
            'name' => fake()->unique()->words(3, true),
            'description' => fake()->optional()->sentence(),
            'starts_at' => fake()->dateTimeBetween('+1 day', '+1 month')->format('Y-m-d'),
            'ends_at' => fake()->dateTimeBetween('+1 month', '+2 months')->format('Y-m-d'),
            'is_active' => true,
        ];
    }
}
