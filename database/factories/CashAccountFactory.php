<?php

namespace Database\Factories;

use App\Models\CashAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CashAccount>
 */
class CashAccountFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'type' => fake()->randomElement(['tunai', 'bank']),
            'account_number' => fake()->optional()->numerify('################'),
            'bank_name' => fake()->optional()->company(),
            'is_active' => true,
        ];
    }
}
