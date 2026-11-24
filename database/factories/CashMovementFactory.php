<?php

namespace Database\Factories;

use App\Models\CashAccount;
use App\Models\CashMovement;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CashMovement>
 */
class CashMovementFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'cash_account_id' => CashAccount::factory(),
            'kind' => 'income',
            'direction' => 'in',
            'transfer_leg' => null,
            'amount' => '100.00',
            'idempotency_key' => fake()->unique()->uuid(),
            'occurred_at' => now(),
            'recorded_at' => now(),
            'created_by' => User::factory(),
        ];
    }
}
