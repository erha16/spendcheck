<?php

namespace Database\Factories;

use App\Models\AdvanceSettlementAttachment;
use App\Models\AdvanceSettlementItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AdvanceSettlementAttachment>
 */
class AdvanceSettlementAttachmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'advance_settlement_item_id' => AdvanceSettlementItem::factory(),
            'original_name' => 'receipt.pdf',
            'disk' => 'local',
            'path' => 'operational-advances/'.fake()->uuid().'/settlement/'.fake()->uuid().'.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 128,
            'checksum' => hash('sha256', fake()->uuid()),
            'uploaded_by' => User::factory(),
        ];
    }
}
