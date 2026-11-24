<?php

namespace Database\Factories;

use App\Models\CashAccount;
use App\Models\PaymentMethod;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Transaction>
 */
class TransactionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => fake()->randomElement(['income', 'expense', 'transfer']),
            'occurred_at' => fake()->dateTimeBetween('-1 year'),
            'description' => fake()->sentence(),
            'responsible_user_id' => User::factory(),
            'created_by' => User::factory(),
            'payment_method_id' => PaymentMethod::factory(),
            'source_cash_account_id' => CashAccount::factory(),
            'total_amount' => '0.00',
            'status' => 'draft',
        ];
    }
}
