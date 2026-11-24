<?php

namespace Tests\Feature;

use App\Models\AdvanceSettlement;
use App\Models\AdvanceSettlementItem;
use App\Models\Category;
use App\Models\OperationalAdvance;
use App\Models\Role;
use App\Models\Subcategory;
use App\Models\User;
use App\Services\AdvanceSettlementWorkflow;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdvanceSettlementWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->seed(AccessControlSeeder::class);
    }

    public function test_recipient_can_submit_a_batch_with_multiple_items_and_private_evidence_without_cash_movement(): void
    {
        [$advance, $recipient] = $this->disbursedAdvance();
        $category = Category::factory()->create();

        $response = $this->actingAs($recipient)->post(route('operational-advances.settlements.store', $advance), [
            'submission_key' => (string) Str::uuid7(),
            'items' => [
                $this->itemPayload($category, '500.00'),
                $this->itemPayload($category, '350.00'),
            ],
        ]);

        $batch = AdvanceSettlement::query()->where('operational_advance_id', $advance->id)->firstOrFail();
        $this->assertSame(1, $batch->batch_number);
        $this->assertSame('submitted', $batch->status);
        $this->assertCount(2, $batch->items);
        $this->assertDatabaseCount('advance_settlement_attachments', 2);
        $this->assertSame('settlement_in_review', $advance->fresh()->status);
        $this->assertDatabaseCount('cash_movements', 0);
        $this->assertDatabaseHas('audit_logs', ['action' => 'advance_settlement_submitted']);
        $response->assertRedirect(route('operational-advances.show', $advance));

        Storage::disk('local')->assertExists($batch->items->first()->attachments->first()->path);
    }

    public function test_partially_accounted_advance_accepts_a_later_batch_and_calculates_cumulative_totals_once(): void
    {
        [$advance, $recipient, $finance] = $this->disbursedAdvance();
        $category = Category::factory()->create();
        $first = $this->submitBatch($advance, $recipient, $category, '600.00');
        $this->reviewItem($advance, $first, $first->items->first(), $finance, 'accepted');

        $this->assertSame('partially_accounted', $advance->fresh()->status);

        $second = $this->submitBatch($advance->fresh(), $recipient, $category, '250.00');
        $this->assertSame(2, $second->batch_number);
        $this->reviewItem($advance, $second, $second->items->first(), $finance, 'accepted');

        $this->assertSame('partially_accounted', $advance->fresh()->status);
        $summary = app(AdvanceSettlementWorkflow::class)->summary($advance->fresh());
        $this->assertSame('1000.00', $summary['disbursed']);
        $this->assertSame('850.00', $summary['accepted_expense']);
        $this->assertSame('150.00', $summary['outstanding']);
        $this->assertSame('0.00', $summary['overspend']);
        $this->assertDatabaseCount('advance_settlements', 2);
        $this->assertDatabaseCount('cash_movements', 0);
    }

    public function test_repeated_submission_key_returns_the_existing_batch_and_does_not_duplicate_items(): void
    {
        [$advance, $recipient] = $this->disbursedAdvance();
        $category = Category::factory()->create();
        $payload = [
            'submission_key' => (string) Str::uuid7(),
            'items' => [$this->itemPayload($category, '100.00')],
        ];

        $this->actingAs($recipient)->post(route('operational-advances.settlements.store', $advance), $payload)->assertRedirect();
        $this->post(route('operational-advances.settlements.store', $advance), $payload)->assertRedirect();

        $this->assertDatabaseCount('advance_settlements', 1);
        $this->assertDatabaseCount('advance_settlement_items', 1);
        $this->assertDatabaseCount('advance_settlement_attachments', 1);
    }

    public function test_batch_cannot_be_submitted_while_review_is_open_or_for_closed_statuses(): void
    {
        [$advance, $recipient, $finance] = $this->disbursedAdvance();
        $category = Category::factory()->create();
        $first = $this->submitBatch($advance, $recipient, $category, '100.00');
        $this->actingAs($recipient)->post(route('operational-advances.settlements.store', $advance), [
            'submission_key' => (string) Str::uuid7(),
            'items' => [$this->itemPayload($category, '50.00')],
        ])->assertSessionHasErrors('operational_advance');

        $this->reviewItem($advance, $first, $first->items->first(), $finance, 'accepted');
        foreach (['settled', 'cancelled', 'rejected'] as $status) {
            $advance->forceFill(['status' => $status])->save();
            $this->post(route('operational-advances.settlements.store', $advance), [
                'submission_key' => (string) Str::uuid7(),
                'items' => [$this->itemPayload($category, '50.00')],
            ])->assertSessionHasErrors('operational_advance');
        }

        $this->assertDatabaseCount('advance_settlements', 1);
    }

    public function test_old_revision_versions_do_not_count_when_latest_version_is_accepted(): void
    {
        [$advance, $recipient, $finance] = $this->disbursedAdvance();
        $category = Category::factory()->create();
        $batch = $this->submitBatch($advance, $recipient, $category, '500.00');
        $original = $batch->items->first();
        $this->reviewItem($advance, $batch, $original, $finance, 'revision_requested', 'Bukti kurang jelas.');

        $replacement = $this->reviseItem($advance, $original, $recipient, $category, '400.00');
        $this->reviewItem($advance, $batch, $replacement, $finance, 'revision_requested', 'Tanggal perlu diperbaiki.');
        $latest = $this->reviseItem($advance, $replacement, $recipient, $category, '300.00');
        $this->reviewItem($advance, $batch, $latest, $finance, 'accepted');

        $this->assertSame('superseded', $original->fresh()->review_status);
        $this->assertSame('superseded', $replacement->fresh()->review_status);
        $this->assertSame('accepted', $latest->fresh()->review_status);
        $this->assertSame('300.00', app(AdvanceSettlementWorkflow::class)->summary($advance->fresh())['accepted_expense']);
        $this->assertDatabaseCount('advance_settlement_items', 3);
        $this->assertDatabaseCount('cash_movements', 0);
    }

    public function test_rejected_and_revision_requested_items_are_excluded_from_final_expense(): void
    {
        [$advance, $recipient, $finance] = $this->disbursedAdvance();
        $category = Category::factory()->create();
        $batch = $this->submitBatch($advance, $recipient, $category, '700.00');
        $item = $batch->items->first();
        $this->reviewItem($advance, $batch, $item, $finance, 'rejected', 'Bukti tidak valid.');

        $summary = app(AdvanceSettlementWorkflow::class)->summary($advance->fresh());
        $this->assertSame('0.00', $summary['accepted_expense']);
        $this->assertSame('1000.00', $summary['outstanding']);
        $this->assertSame('disbursed', $advance->fresh()->status);
    }

    public function test_approved_adjustment_replaces_accepted_amount_without_a_second_cash_movement(): void
    {
        [$advance, $recipient, $finance] = $this->disbursedAdvance();
        $category = Category::factory()->create();
        $batch = $this->submitBatch($advance, $recipient, $category, '400.00');
        $item = $batch->items->first();
        $this->reviewItem($advance, $batch, $item, $finance, 'accepted');
        $groupId = (string) Str::uuid7();

        $this->actingAs($recipient)->post(route('operational-advances.settlement-items.adjustments.store', [$advance, $item]), [
            'adjustment_group_id' => $groupId,
            'mode' => 'replace',
            'occurred_at' => now()->subHour()->format('Y-m-d\\TH:i'),
            'reason' => 'Nominal pada bukti perlu dikoreksi.',
            'amount' => '250.00',
            'description' => 'Nilai sesuai kuitansi yang benar',
            'category_id' => $category->id,
        ])->assertRedirect();

        $this->assertSame('400.00', app(AdvanceSettlementWorkflow::class)->summary($advance->fresh())['accepted_expense']);
        $this->actingAs($finance)->post(route('operational-advances.settlement-items.adjustments.review', [$advance, $item, $groupId]), [
            'decision' => 'approved',
        ])->assertRedirect();

        $this->assertSame('250.00', app(AdvanceSettlementWorkflow::class)->summary($advance->fresh())['accepted_expense']);
        $this->assertSame('partially_accounted', $advance->fresh()->status);
        $this->assertDatabaseCount('advance_settlement_item_adjustments', 2);
        $this->assertDatabaseCount('cash_movements', 0);
    }

    public function test_recipient_is_isolated_and_read_only_roles_cannot_submit_or_review(): void
    {
        [$advance, $recipient, $finance] = $this->disbursedAdvance();
        $category = Category::factory()->create();
        $otherRecipient = $this->userWithRole('driver');
        $this->actingAs($otherRecipient)->get(route('operational-advances.settlements.create', $advance))->assertNotFound();
        $this->post(route('operational-advances.settlements.store', $advance), [
            'submission_key' => (string) Str::uuid7(),
            'items' => [$this->itemPayload($category, '100.00')],
        ])->assertForbidden();

        $batch = $this->submitBatch($advance, $recipient, $category, '100.00');
        foreach (['kepala_sarpras', 'divisi_keuangan', 'admin_system'] as $role) {
            $user = $this->userWithRole($role);
            $this->actingAs($user)->post(route('operational-advances.settlement-items.review', [$advance, $batch, $batch->items->first()]), [
                'decision' => 'accepted',
            ])->assertForbidden();
        }

        $this->actingAs($finance)->post(route('operational-advances.settlement-items.review', [$advance, $batch, $batch->items->first()]), [
            'decision' => 'accepted',
        ])->assertRedirect();
    }

    public function test_category_subcategory_pairing_and_evidence_are_validated(): void
    {
        [$advance, $recipient] = $this->disbursedAdvance();
        $category = Category::factory()->create();
        $otherCategory = Category::factory()->create();
        $subcategory = Subcategory::factory()->create(['category_id' => $otherCategory->id]);
        $payload = $this->itemPayload($category, '100.00');
        $payload['subcategory_id'] = $subcategory->id;

        $this->actingAs($recipient)->post(route('operational-advances.settlements.store', $advance), [
            'submission_key' => (string) Str::uuid7(),
            'items' => [$payload],
        ])->assertSessionHasErrors('items.0.subcategory_id');

        $payload = $this->itemPayload($category, '100.00');
        unset($payload['attachments']);
        $this->post(route('operational-advances.settlements.store', $advance), [
            'submission_key' => (string) Str::uuid7(),
            'items' => [$payload],
        ])->assertSessionHasErrors('items.0.attachments');
        $this->assertDatabaseCount('advance_settlements', 0);
    }

    public function test_only_non_participant_finance_can_review_and_reviewer_cannot_review_own_assisted_submission(): void
    {
        [$advance, $recipient, $finance] = $this->disbursedAdvance();
        $category = Category::factory()->create();
        $batch = $this->submitBatch($advance, $recipient, $category, '100.00');
        $item = $batch->items->first();

        $this->actingAs($recipient)->post(route('operational-advances.settlement-items.review', [$advance, $batch, $item]), [
            'decision' => 'accepted',
        ])->assertForbidden();

        $financeAdvance = $this->advanceFor($finance, 'disbursed');
        $financeBatch = $this->submitBatch($financeAdvance, $finance, $category, '100.00');
        $this->post(route('operational-advances.settlement-items.review', [$financeAdvance, $financeBatch, $financeBatch->items->first()]), [
            'decision' => 'accepted',
        ])->assertForbidden();
    }

    /** @return array{OperationalAdvance, User, User} */
    private function disbursedAdvance(): array
    {
        $recipient = $this->userWithRole('driver');
        $finance = $this->userWithRole('keuangan_sarpras');

        return [$this->advanceFor($recipient, 'disbursed'), $recipient, $finance];
    }

    private function advanceFor(User $recipient, string $status): OperationalAdvance
    {
        return OperationalAdvance::factory()->create([
            'requester_user_id' => $recipient->id,
            'responsible_user_id' => $recipient->id,
            'created_by' => $recipient->id,
            'status' => $status,
            'approved_amount' => '1000.00',
            'disbursed_amount' => '1000.00',
            'disbursed_at' => now()->subDay(),
        ]);
    }

    private function submitBatch(OperationalAdvance $advance, User $recipient, Category $category, string $amount): AdvanceSettlement
    {
        $this->actingAs($recipient)->post(route('operational-advances.settlements.store', $advance), [
            'submission_key' => (string) Str::uuid7(),
            'items' => [$this->itemPayload($category, $amount)],
        ])->assertRedirect();

        return AdvanceSettlement::query()->where('operational_advance_id', $advance->id)->latest('batch_number')->firstOrFail()->load('items');
    }

    /** @return array<string, mixed> */
    private function itemPayload(Category $category, string $amount): array
    {
        return [
            'occurred_at' => now()->subHour()->format('Y-m-d\\TH:i'),
            'description' => 'Pembelian perlengkapan kegiatan',
            'category_id' => $category->id,
            'amount' => $amount,
            'attachments' => [UploadedFile::fake()->createWithContent('bukti.pdf', "%PDF-1.4\ncontoh bukti")],
        ];
    }

    private function reviewItem(
        OperationalAdvance $advance,
        AdvanceSettlement $batch,
        AdvanceSettlementItem $item,
        User $finance,
        string $decision,
        ?string $reason = null,
    ): void {
        $this->actingAs($finance)->post(route('operational-advances.settlement-items.review', [$advance, $batch, $item]), [
            'decision' => $decision,
            'reason' => $reason,
        ])->assertRedirect();
    }

    private function reviseItem(
        OperationalAdvance $advance,
        AdvanceSettlementItem $original,
        User $recipient,
        Category $category,
        string $amount,
    ): AdvanceSettlementItem {
        $payload = $this->itemPayload($category, $amount);
        $this->actingAs($recipient)->post(route('operational-advances.settlement-items.revisions.store', [$advance, $original]), $payload)
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        return AdvanceSettlementItem::query()->where('replaces_item_id', $original->id)->firstOrFail();
    }

    private function userWithRole(string $roleName): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('name', $roleName)->firstOrFail());

        return $user;
    }
}
