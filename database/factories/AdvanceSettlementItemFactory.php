<?php

namespace Database\Factories;

use App\Models\AdvanceSettlement;
use App\Models\AdvanceSettlementItem;
use App\Models\AdvanceSettlementItemStatus;
use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AdvanceSettlementItem>
 */
class AdvanceSettlementItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'advance_settlement_id' => AdvanceSettlement::factory(),
            'occurred_at' => now()->subDay(),
            'description' => fake()->sentence(),
            'category_id' => Category::factory(),
            'amount' => '100.00',
            'review_status' => AdvanceSettlementItemStatus::Pending->value,
        ];
    }
}
