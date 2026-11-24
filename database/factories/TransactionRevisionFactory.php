<?php

namespace Database\Factories;

use App\Models\Transaction;
use App\Models\TransactionRevision;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TransactionRevision>
 */
class TransactionRevisionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'transaction_id' => Transaction::factory(),
            'replacement_transaction_id' => Transaction::factory(),
            'revision_number' => 1,
            'reason' => fake()->sentence(),
            'before_values' => [],
            'after_values' => [],
            'corrected_by' => User::factory(),
            'corrected_at' => now(),
        ];
    }
}
