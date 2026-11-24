<?php

namespace Database\Factories;

use App\Models\Activity;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\TransactionItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TransactionItem>
 */
class TransactionItemFactory extends Factory
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
            'description' => fake()->words(3, true),
            'category_id' => Category::factory(),
            'activity_id' => Activity::factory(),
            'quantity' => '1.0000',
            'unit' => 'unit',
            'unit_price' => '100.00',
            'subtotal' => '100.00',
        ];
    }
}
