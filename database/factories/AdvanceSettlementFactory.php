<?php

namespace Database\Factories;

use App\Models\AdvanceSettlement;
use App\Models\AdvanceSettlementStatus;
use App\Models\OperationalAdvance;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AdvanceSettlement>
 */
class AdvanceSettlementFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'operational_advance_id' => OperationalAdvance::factory(),
            'batch_number' => 1,
            'status' => AdvanceSettlementStatus::Draft->value,
        ];
    }
}
