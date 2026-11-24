<?php

namespace Tests\Feature;

use App\Models\AdvanceAttachment;
use App\Models\AdvanceNumberSequence;
use App\Models\AdvanceSettlement;
use App\Models\AdvanceSettlementAttachment;
use App\Models\AdvanceSettlementItem;
use App\Models\AdvanceSettlementItemAdjustment;
use App\Models\OperationalAdvance;
use App\Services\OperationalAdvanceNumberGenerator;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Tests\TestCase;

class OperationalAdvanceDomainTest extends TestCase
{
    use RefreshDatabase;

    public function test_phase_three_b_tables_exist_and_models_have_uuid_v7_and_numbered_relationships(): void
    {
        $tables = [
            'advance_number_sequences', 'operational_advances', 'advance_attachments', 'advance_settlements',
            'advance_settlement_items', 'advance_settlement_attachments', 'advance_settlement_item_adjustments',
        ];

        foreach ($tables as $table) {
            $this->assertTrue(Schema::hasTable($table), "Expected {$table} to exist.");
        }

        $advance = OperationalAdvance::factory()->create();
        $settlement = AdvanceSettlement::factory()->for($advance)->create();
        $item = AdvanceSettlementItem::factory()->for($settlement, 'settlement')->create(['review_status' => 'accepted']);
        $advanceAttachment = AdvanceAttachment::factory()->for($advance)->create();
        $settlementAttachment = AdvanceSettlementAttachment::factory()->for($item, 'item')->create();
        $adjustment = AdvanceSettlementItemAdjustment::factory()->for($item, 'item')->create();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $advance->public_id);
        $this->assertStringStartsWith('BON/', $advance->number);
        $this->assertTrue($advance->settlements->contains($settlement));
        $this->assertTrue($advance->attachments->contains($advanceAttachment));
        $this->assertTrue($settlement->items->contains($item));
        $this->assertTrue($item->attachments->contains($settlementAttachment));
        $this->assertTrue($item->adjustments->contains($adjustment));
    }

    public function test_advance_number_generator_uses_a_locked_monthly_sequence(): void
    {
        $generator = app(OperationalAdvanceNumberGenerator::class);

        $firstNumber = $generator->next('2026-06-12');
        $secondNumber = $generator->next('2026-06-30');
        $nextMonthNumber = $generator->next('2026-07-01');

        $this->assertSame('BON/202606/0001', $firstNumber);
        $this->assertSame('BON/202606/0002', $secondNumber);
        $this->assertSame('BON/202607/0001', $nextMonthNumber);
        $this->assertSame(2, AdvanceNumberSequence::query()->where('period', '202606')->value('last_number'));
    }

    public function test_advance_number_is_unique(): void
    {
        $advance = OperationalAdvance::factory()->create();

        $this->expectException(QueryException::class);

        OperationalAdvance::factory()->create(['number' => $advance->number]);
    }

    public function test_batch_number_is_unique_within_an_advance(): void
    {
        $advance = OperationalAdvance::factory()->create();
        AdvanceSettlement::factory()->for($advance)->create(['batch_number' => 1]);

        $this->expectException(QueryException::class);

        AdvanceSettlement::factory()->for($advance)->create(['batch_number' => 1]);
    }

    public function test_settlement_item_can_only_have_one_direct_replacement(): void
    {
        $settlement = AdvanceSettlement::factory()->create();
        $originalItem = AdvanceSettlementItem::factory()->for($settlement, 'settlement')->create();
        AdvanceSettlementItem::factory()->for($settlement, 'settlement')->create(['replaces_item_id' => $originalItem->id]);

        $this->expectException(QueryException::class);

        AdvanceSettlementItem::factory()->for($settlement, 'settlement')->create(['replaces_item_id' => $originalItem->id]);
    }

    public function test_attachment_paths_are_unique(): void
    {
        $advance = OperationalAdvance::factory()->create();
        $attachment = AdvanceAttachment::factory()->for($advance)->create();

        $this->expectException(QueryException::class);

        AdvanceAttachment::factory()->for($advance)->create(['path' => $attachment->path]);
    }

    public function test_foreign_keys_restrict_deleting_bon_with_settlement_history(): void
    {
        $advance = OperationalAdvance::factory()->create();
        AdvanceSettlement::factory()->for($advance)->create();

        $this->expectException(QueryException::class);

        DB::table('operational_advances')->where('id', $advance->id)->delete();
    }

    public function test_adjustment_requires_an_accepted_item_and_correct_signed_amount(): void
    {
        $item = AdvanceSettlementItem::factory()->create(['review_status' => 'pending']);

        try {
            AdvanceSettlementItemAdjustment::factory()->for($item, 'item')->create();
            $this->fail('An adjustment cannot be created for an unaccepted item.');
        } catch (\InvalidArgumentException) {
            $this->assertDatabaseCount('advance_settlement_item_adjustments', 0);
        }

        $acceptedItem = AdvanceSettlementItem::factory()->create(['review_status' => 'accepted']);

        $this->expectException(\InvalidArgumentException::class);

        AdvanceSettlementItemAdjustment::factory()->for($acceptedItem, 'item')->create(['amount_delta' => '10.00']);
    }

    public function test_adjustments_and_attachments_are_append_only(): void
    {
        $advance = OperationalAdvance::factory()->create();
        $settlement = AdvanceSettlement::factory()->for($advance)->create();
        $item = AdvanceSettlementItem::factory()->for($settlement, 'settlement')->create(['review_status' => 'accepted']);
        $adjustment = AdvanceSettlementItemAdjustment::factory()->for($item, 'item')->create();
        $attachment = AdvanceAttachment::factory()->for($advance)->create();

        try {
            $adjustment->update(['reason' => 'Diubah']);
            $this->fail('Adjustments must be append-only.');
        } catch (LogicException) {
            $this->assertModelExists($adjustment);
        }

        $this->expectException(LogicException::class);

        $attachment->delete();
    }
}
