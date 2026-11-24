<?php

namespace Database\Factories;

use App\Models\Activity;
use App\Models\CashAccount;
use App\Models\OperationalAdvance;
use App\Models\OperationalAdvanceStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OperationalAdvance>
 */
class OperationalAdvanceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'status' => OperationalAdvanceStatus::Draft->value,
            'purpose' => fake()->sentence(),
            'requested_amount' => '1000.00',
            'requester_user_id' => User::factory(),
            'responsible_user_id' => User::factory(),
            'created_by' => User::factory(),
            'activity_id' => Activity::factory(),
            'planned_cash_account_id' => CashAccount::factory(),
            'due_date' => now()->addDays(14)->toDateString(),
        ];
    }
}
