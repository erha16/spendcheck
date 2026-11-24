<?php

namespace Database\Factories;

use App\Models\AdvanceSettlementAdjustmentType;
use App\Models\AdvanceSettlementItem;
use App\Models\AdvanceSettlementItemAdjustment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AdvanceSettlementItemAdjustment>
 */
class AdvanceSettlementItemAdjustmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'adjustment_group_id' => (string) Str::uuid7(),
            'advance_settlement_item_id' => AdvanceSettlementItem::factory(),
            'kind' => AdvanceSettlementAdjustmentType::Reversal->value,
            'status' => 'pending',
            'occurred_at' => now()->subDay(),
            'amount_delta' => '-10.00',
            'reason' => fake()->sentence(),
            'created_by' => User::factory(),
            'created_at' => now(),
        ];
    }
}
