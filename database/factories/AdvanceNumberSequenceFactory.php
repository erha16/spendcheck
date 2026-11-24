<?php

namespace Database\Factories;

use App\Models\AdvanceNumberSequence;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AdvanceNumberSequence>
 */
class AdvanceNumberSequenceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'period' => now()->format('Ym'),
            'last_number' => 0,
        ];
    }
}
