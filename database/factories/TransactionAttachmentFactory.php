<?php

namespace Database\Factories;

use App\Models\Transaction;
use App\Models\TransactionAttachment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TransactionAttachment>
 */
class TransactionAttachmentFactory extends Factory
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
            'original_name' => fake()->word().'.pdf',
            'disk' => 'local',
            'path' => 'transactions/'.fake()->uuid().'/'.fake()->uuid().'.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
            'checksum' => hash('sha256', fake()->uuid()),
            'uploaded_by' => User::factory(),
        ];
    }
}
