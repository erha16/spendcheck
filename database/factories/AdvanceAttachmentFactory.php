<?php

namespace Database\Factories;

use App\Models\AdvanceAttachment;
use App\Models\OperationalAdvance;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AdvanceAttachment>
 */
class AdvanceAttachmentFactory extends Factory
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
            'original_name' => 'receipt.pdf',
            'disk' => 'local',
            'path' => 'operational-advances/'.fake()->uuid().'/request/'.fake()->uuid().'.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 128,
            'checksum' => hash('sha256', fake()->uuid()),
            'uploaded_by' => User::factory(),
        ];
    }
}
