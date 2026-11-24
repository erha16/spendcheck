<?php

namespace Database\Factories;

use App\Models\CashAccount;
use App\Models\OpeningBalanceRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OpeningBalanceRequest>
 */
class OpeningBalanceRequestFactory extends Factory
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
            'amount' => '1000.00',
            'effective_at' => now()->subDay(),
            'reason' => fake()->sentence(),
            'status' => 'pending',
            'proposed_by' => User::factory(),
            'proposed_at' => now(),
        ];
    }
}
