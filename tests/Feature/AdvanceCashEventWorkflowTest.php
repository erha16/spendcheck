<?php

namespace Tests\Feature;

use App\Models\AdvanceSettlement;
use App\Models\AdvanceSettlementItem;
use App\Models\CashAccount;
use App\Models\CashMovement;
use App\Models\Category;
use App\Models\OperationalAdvance;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\User;
use App\Services\AdvanceSettlementWorkflow;
use App\Services\OperationalAdvanceReconciliationService;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdvanceCashEventWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
    }

    public function test_return_can_be_submitted_retried_and_received_without_becoming_income_or_expense(): void
    {
        [$advance, $recipient, $finance] = $this->advanceWithPayout();
        $this->addAcceptedExpense($advance, $recipient, '850.00');
        $workflow = app(AdvanceSettlementWorkflow::class);
        $workflow->refreshAdvanceStatus($advance->fresh(), $finance);
        $account = CashAccount::factory()->create();
        $key = (string) Str::uuid7();

        $this->actingAs($recipient)->post(route('operational-advances.returns.store', $advance), $this->returnPayload($key, '150.00'))
            ->assertRedirect();
        $returnRequest = Transaction::query()->where('advance_event_key', $key)->firstOrFail();
        $this->post(route('operational-advances.returns.store', $advance), $this->returnPayload($key, '150.00'))->assertRedirect();

        $this->assertSame('submitted', $returnRequest->fresh()->status);
        $this->assertDatabaseCount('transactions', 2);
        $this->assertDatabaseCount('cash_movements', 1);
        $this->assertSame('return_due', $advance->fresh()->status);

        $inactiveAccount = CashAccount::factory()->create(['is_active' => false]);
        $this->actingAs($finance)->post(route('operational-advances.returns.receive', [$advance, $returnRequest]), [
            'cash_account_id' => $inactiveAccount->id,
        ])->assertSessionHasErrors('cash_account_id');

        $this->actingAs($finance)->post(route('operational-advances.returns.receive', [$advance, $returnRequest]), [
            'cash_account_id' => $account->id,
        ])->assertRedirect();
        $this->post(route('operational-advances.returns.receive', [$advance, $returnRequest]), [
            'cash_account_id' => $account->id,
        ])->assertRedirect();

        $summary = $workflow->summary($advance->fresh());
        $this->assertSame('1000.00', $summary['disbursed']);
        $this->assertSame('850.00', $summary['accepted_expense']);
        $this->assertSame('150.00', $summary['returned']);
        $this->assertSame('0.00', $summary['return_outstanding']);
        $this->assertSame('0.00', $summary['reimbursement_outstanding']);
        $this->assertSame('settled', $advance->fresh()->status);
        $this->assertDatabaseCount('transactions', 2);
        $this->assertDatabaseCount('transaction_items', 0);
        $this->assertDatabaseCount('cash_movements', 2);
        $this->assertDatabaseHas('cash_movements', [
            'transaction_id' => $returnRequest->id,
            'kind' => 'advance_return',
            'direction' => 'in',
            'amount' => '150.00',
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'operational_advance_return_received']);
    }

    public function test_partial_return_and_new_batch_from_return_due_recalculate_all_obligations(): void
    {
        [$advance, $recipient, $finance] = $this->advanceWithPayout();
        $category = Category::factory()->create();
        $this->addAcceptedExpense($advance, $recipient, '850.00');
        $workflow = app(AdvanceSettlementWorkflow::class);
        $workflow->refreshAdvanceStatus($advance->fresh(), $finance);
        $returnAccount = CashAccount::factory()->create();
        $this->actingAs($recipient)->post(route('operational-advances.returns.store', $advance), $this->returnPayload((string) Str::uuid7(), '100.00'))->assertRedirect();
        $return = Transaction::query()->where('type', 'advance_return')->firstOrFail();
        $this->actingAs($finance)->post(route('operational-advances.returns.receive', [$advance, $return]), ['cash_account_id' => $returnAccount->id])->assertRedirect();
        $this->assertSame('return_due', $advance->fresh()->status);
        $this->assertSame('50.00', $workflow->summary($advance->fresh())['return_outstanding']);

        $batch = $this->submitBatch($advance->fresh(), $recipient, $category, '50.00');
        $this->assertSame('settlement_in_review', $advance->fresh()->status);
        $this->reviewItem($advance, $batch, $batch->items->first(), $finance);

        $summary = $workflow->summary($advance->fresh());
        $this->assertSame('900.00', $summary['accepted_expense']);
        $this->assertSame('100.00', $summary['returned']);
        $this->assertSame('0.00', $summary['return_outstanding']);
        $this->assertSame('0.00', $summary['reimbursement_outstanding']);
        $this->assertSame('settled', $advance->fresh()->status);
        $this->assertDatabaseCount('cash_movements', 2);
    }

    public function test_over_return_is_rejected_and_finance_can_reject_a_return_without_cash_movement(): void
    {
        [$advance, $recipient, $finance] = $this->advanceWithPayout();
        $this->addAcceptedExpense($advance, $recipient, '850.00');
        $workflow = app(AdvanceSettlementWorkflow::class);
        $workflow->refreshAdvanceStatus($advance->fresh(), $finance);
        $key = (string) Str::uuid7();

        $this->actingAs($recipient)->post(route('operational-advances.returns.store', $advance), $this->returnPayload($key, '151.00'))
            ->assertSessionHasErrors('amount');
        $this->post(route('operational-advances.returns.store', $advance), $this->returnPayload($key, '100.00'))->assertRedirect();
        $return = Transaction::query()->where('advance_event_key', $key)->firstOrFail();

        $this->actingAs($finance)->post(route('operational-advances.returns.reject', [$advance, $return]), [
            'reason' => 'Bukti penerimaan belum dapat diverifikasi.',
        ])->assertRedirect();

        $this->assertSame('rejected', $return->fresh()->status);
        $this->assertSame('150.00', $workflow->summary($advance->fresh())['return_outstanding']);
        $this->assertSame('return_due', $advance->fresh()->status);
        $this->assertDatabaseCount('cash_movements', 1);
        $this->assertDatabaseHas('audit_logs', ['action' => 'operational_advance_return_rejected']);
    }

    public function test_return_is_revalidated_at_receipt_after_accepted_expense_changes(): void
    {
        [$advance, $recipient, $finance] = $this->advanceWithPayout();
        $this->addAcceptedExpense($advance, $recipient, '850.00');
        app(AdvanceSettlementWorkflow::class)->refreshAdvanceStatus($advance->fresh(), $finance);
        $account = CashAccount::factory()->create();
        $this->actingAs($recipient)->post(route('operational-advances.returns.store', $advance), $this->returnPayload((string) Str::uuid7(), '150.00'))->assertRedirect();
        $return = Transaction::query()->where('type', 'advance_return')->firstOrFail();
        $this->addAcceptedExpense($advance, $recipient, '100.00', 2);

        $this->actingAs($finance)->post(route('operational-advances.returns.receive', [$advance, $return]), ['cash_account_id' => $account->id])
            ->assertSessionHasErrors('amount');

        $this->assertSame('submitted', $return->fresh()->status);
        $this->assertDatabaseCount('cash_movements', 1);
    }

    public function test_accepted_overspend_creates_payable_and_partial_repayments_change_cash_only(): void
    {
        [$advance, $recipient, $finance] = $this->advanceWithPayout();
        $this->addAcceptedExpense($advance, $recipient, '1200.00');
        $workflow = app(AdvanceSettlementWorkflow::class);
        $workflow->refreshAdvanceStatus($advance->fresh(), $finance);
        $account = CashAccount::factory()->create();

        $this->actingAs($finance)->post(route('operational-advances.reimbursements.store', $advance), $this->repaymentPayload((string) Str::uuid7(), $account, '1.00'))
            ->assertSessionHasErrors('cash_account_id');

        $this->createOpeningMovement($account, $finance, '500.00');

        $this->assertSame('reimbursement_due', $advance->fresh()->status);
        $this->assertSame('200.00', $workflow->summary($advance->fresh())['reimbursement_outstanding']);
        $key = (string) Str::uuid7();
        $payload = $this->repaymentPayload($key, $account, '75.00');
        $this->actingAs($finance)->post(route('operational-advances.reimbursements.store', $advance), $payload)->assertRedirect();
        $firstRepayment = Transaction::query()->where('advance_event_key', $key)->firstOrFail();
        $this->post(route('operational-advances.reimbursements.store', $advance), $payload)->assertRedirect();
        $this->assertSame(1, Transaction::query()->where('advance_event_key', $key)->count());
        $this->assertSame('125.00', $workflow->summary($advance->fresh())['reimbursement_outstanding']);

        $this->post(route('operational-advances.reimbursements.store', $advance), $this->repaymentPayload((string) Str::uuid7(), $account, '125.00'))->assertRedirect();

        $summary = $workflow->summary($advance->fresh());
        $this->assertSame('1200.00', $summary['accepted_expense']);
        $this->assertSame('200.00', $summary['reimbursed']);
        $this->assertSame('0.00', $summary['reimbursement_outstanding']);
        $this->assertSame('settled', $advance->fresh()->status);
        $this->assertSame('300.00', $account->fresh()->ledgerBalance());
        $this->assertSame(2, CashMovement::query()->where('kind', 'advance_repay')->count());
        $this->assertDatabaseCount('transaction_items', 0);
        $this->assertDatabaseHas('cash_movements', [
            'transaction_id' => $firstRepayment->id,
            'kind' => 'advance_repay',
            'direction' => 'out',
            'amount' => '75.00',
        ]);
    }

    public function test_new_batch_is_allowed_from_reimbursement_due_and_updates_payable_after_acceptance(): void
    {
        [$advance, $recipient, $finance] = $this->advanceWithPayout();
        $category = Category::factory()->create();
        $this->addAcceptedExpense($advance, $recipient, '1200.00');
        $workflow = app(AdvanceSettlementWorkflow::class);
        $workflow->refreshAdvanceStatus($advance->fresh(), $finance);
        $this->assertSame('reimbursement_due', $advance->fresh()->status);

        $batch = $this->submitBatch($advance->fresh(), $recipient, $category, '100.00');
        $this->reviewItem($advance, $batch, $batch->items->first(), $finance);

        $this->assertSame('1300.00', $workflow->summary($advance->fresh())['accepted_expense']);
        $this->assertSame('300.00', $workflow->summary($advance->fresh())['reimbursement_outstanding']);
        $this->assertSame('reimbursement_due', $advance->fresh()->status);
    }

    public function test_read_only_roles_admin_and_financial_participant_cannot_receive_or_pay(): void
    {
        [$advance, $recipient, $finance] = $this->advanceWithPayout();
        $this->addAcceptedExpense($advance, $recipient, '1200.00');
        app(AdvanceSettlementWorkflow::class)->refreshAdvanceStatus($advance->fresh(), $finance);
        $account = CashAccount::factory()->create();

        foreach (['driver', 'petugas_sarpras', 'kepala_sarpras', 'divisi_keuangan', 'admin_system'] as $role) {
            $user = $this->userWithRole($role);
            $this->actingAs($user)->post(route('operational-advances.reimbursements.store', $advance), $this->repaymentPayload((string) Str::uuid7(), $account, '10.00'))
                ->assertForbidden();
        }

        $selfAdvance = $this->createAdvance($finance);
        $this->addAcceptedExpense($selfAdvance, $recipient, '1200.00');
        app(AdvanceSettlementWorkflow::class)->refreshAdvanceStatus($selfAdvance->fresh(), $finance);
        $this->actingAs($finance)->post(route('operational-advances.reimbursements.store', $selfAdvance), $this->repaymentPayload((string) Str::uuid7(), $account, '10.00'))
            ->assertForbidden();

        $selfReturnAdvance = $this->createAdvance($finance);
        $this->addAcceptedExpense($selfReturnAdvance, $recipient, '850.00');
        app(AdvanceSettlementWorkflow::class)->refreshAdvanceStatus($selfReturnAdvance->fresh(), $finance);
        $selfReturn = app(OperationalAdvanceReconciliationService::class)->submitReturn(
            $selfReturnAdvance->fresh(),
            $finance,
            $this->returnPayload((string) Str::uuid7(), '150.00'),
        );
        $this->post(route('operational-advances.returns.receive', [$selfReturnAdvance, $selfReturn]), ['cash_account_id' => $account->id])
            ->assertForbidden();

        $this->assertDatabaseCount('cash_movements', 3);
    }

    public function test_cash_event_correction_uses_reversal_replacement_and_reopens_obligation(): void
    {
        [$advance, $recipient, $finance] = $this->advanceWithPayout();
        $this->addAcceptedExpense($advance, $recipient, '850.00');
        $settlementWorkflow = app(AdvanceSettlementWorkflow::class);
        $settlementWorkflow->refreshAdvanceStatus($advance->fresh(), $finance);
        $returnAccount = CashAccount::factory()->create();
        $this->actingAs($recipient)->post(route('operational-advances.returns.store', $advance), $this->returnPayload((string) Str::uuid7(), '150.00'))->assertRedirect();
        $original = Transaction::query()->where('type', 'advance_return')->firstOrFail();
        $this->actingAs($finance)->post(route('operational-advances.returns.receive', [$advance, $original]), ['cash_account_id' => $returnAccount->id])->assertRedirect();

        $replacement = app(OperationalAdvanceReconciliationService::class)->correctCashEvent($original->fresh(), $finance, [
            'advance_event_key' => (string) Str::uuid7(),
            'cash_account_id' => $returnAccount->id,
            'amount' => '100.00',
            'occurred_at' => now()->subMinutes(5),
            'correction_reason' => 'Nominal penerimaan perlu dikoreksi.',
        ]);

        $this->assertSame('reversed', $original->fresh()->status);
        $this->assertSame('posted', $replacement->status);
        $this->assertSame($original->id, $replacement->reversal_of_id);
        $this->assertSame('100.00', $settlementWorkflow->summary($advance->fresh())['returned']);
        $this->assertSame('50.00', $settlementWorkflow->summary($advance->fresh())['return_outstanding']);
        $this->assertSame('return_due', $advance->fresh()->status);
        $this->assertDatabaseHas('transaction_revisions', ['transaction_id' => $original->id, 'replacement_transaction_id' => $replacement->id]);
        $this->assertDatabaseHas('cash_movements', ['transaction_id' => $original->id, 'kind' => 'reversal', 'direction' => 'out', 'amount' => '150.00']);
        $this->assertDatabaseCount('cash_movements', 4);
    }

    public function test_repayment_correction_reverses_and_replaces_without_changing_expense(): void
    {
        [$advance, $recipient, $finance] = $this->advanceWithPayout();
        $this->addAcceptedExpense($advance, $recipient, '1200.00');
        $workflow = app(AdvanceSettlementWorkflow::class);
        $workflow->refreshAdvanceStatus($advance->fresh(), $finance);
        $account = CashAccount::factory()->create();
        $this->createOpeningMovement($account, $finance, '500.00');
        $this->actingAs($finance)->post(route('operational-advances.reimbursements.store', $advance), $this->repaymentPayload((string) Str::uuid7(), $account, '200.00'))
            ->assertRedirect();
        $original = Transaction::query()->where('type', 'advance_repay')->firstOrFail();

        $replacement = app(OperationalAdvanceReconciliationService::class)->correctCashEvent($original, $finance, [
            'advance_event_key' => (string) Str::uuid7(),
            'cash_account_id' => $account->id,
            'amount' => '150.00',
            'occurred_at' => now()->subMinutes(5),
            'correction_reason' => 'Nominal pembayaran perlu dikoreksi.',
        ]);

        $summary = $workflow->summary($advance->fresh());
        $this->assertSame('reversed', $original->fresh()->status);
        $this->assertSame('posted', $replacement->status);
        $this->assertSame('1200.00', $summary['accepted_expense']);
        $this->assertSame('150.00', $summary['reimbursed']);
        $this->assertSame('50.00', $summary['reimbursement_outstanding']);
        $this->assertSame('reimbursement_due', $advance->fresh()->status);
        $this->assertDatabaseHas('cash_movements', ['transaction_id' => $original->id, 'kind' => 'reversal', 'direction' => 'in', 'amount' => '200.00']);
        $this->assertDatabaseCount('advance_settlement_items', 1);
    }

    public function test_movement_failure_rolls_back_return_receipt_transaction_and_audit(): void
    {
        [$advance, $recipient, $finance] = $this->advanceWithPayout();
        $this->addAcceptedExpense($advance, $recipient, '850.00');
        app(AdvanceSettlementWorkflow::class)->refreshAdvanceStatus($advance->fresh(), $finance);
        $this->actingAs($recipient)->post(route('operational-advances.returns.store', $advance), $this->returnPayload((string) Str::uuid7(), '150.00'))->assertRedirect();
        $return = Transaction::query()->where('type', 'advance_return')->firstOrFail();
        $account = CashAccount::factory()->create();
        CashMovement::factory()->create([
            'cash_account_id' => $account->id,
            'kind' => 'income',
            'direction' => 'in',
            'amount' => '1.00',
            'idempotency_key' => 'advance-return:'.$advance->public_id.':'.$return->number,
            'created_by' => $finance->id,
        ]);

        $this->actingAs($finance)->post(route('operational-advances.returns.receive', [$advance, $return]), ['cash_account_id' => $account->id])
            ->assertServerError();
        $this->assertSame('submitted', $return->fresh()->status);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'operational_advance_return_received', 'subject_id' => $return->id]);
        $this->assertSame(2, CashMovement::query()->count());
    }

    /** @return array{OperationalAdvance, User, User} */
    private function advanceWithPayout(): array
    {
        $recipient = $this->userWithRole('driver');
        $finance = $this->userWithRole('keuangan_sarpras');

        return [$this->createAdvance($recipient), $recipient, $finance];
    }

    private function createAdvance(User $recipient): OperationalAdvance
    {
        $advance = OperationalAdvance::factory()->create([
            'requester_user_id' => $recipient->id,
            'responsible_user_id' => $recipient->id,
            'created_by' => $recipient->id,
            'status' => 'disbursed',
            'approved_amount' => '1000.00',
            'disbursed_amount' => '1000.00',
            'disbursed_at' => now()->subDay(),
        ]);
        $account = CashAccount::factory()->create();
        $payout = new Transaction;
        $payout->forceFill([
            'type' => 'advance_payout',
            'occurred_at' => now()->subDay(),
            'description' => 'Pencairan '.$advance->number,
            'responsible_user_id' => $recipient->id,
            'created_by' => $recipient->id,
            'payment_method_id' => null,
            'source_cash_account_id' => $account->id,
            'destination_cash_account_id' => null,
            'vendor_id' => null,
            'fund_source_id' => null,
            'operational_advance_id' => $advance->id,
            'total_amount' => '1000.00',
            'status' => 'posted',
            'posted_by' => $recipient->id,
            'posted_at' => now()->subDay(),
        ])->save();
        CashMovement::create([
            'cash_account_id' => $account->id,
            'transaction_id' => $payout->id,
            'kind' => 'advance_payout',
            'direction' => 'out',
            'amount' => '1000.00',
            'idempotency_key' => 'advance-payout-feature:'.$advance->public_id,
            'occurred_at' => $payout->occurred_at,
            'recorded_at' => now()->subDay(),
            'created_by' => $recipient->id,
        ]);

        return $advance;
    }

    private function addAcceptedExpense(OperationalAdvance $advance, User $submitter, string $amount, int $batchNumber = 1): AdvanceSettlementItem
    {
        $category = Category::factory()->create();
        $batch = AdvanceSettlement::factory()->create([
            'operational_advance_id' => $advance->id,
            'batch_number' => $batchNumber,
            'status' => 'completed',
            'submitted_by' => $submitter->id,
            'submitted_at' => now()->subMinutes(2),
            'reviewed_by' => $this->userWithRole('keuangan_sarpras')->id,
            'reviewed_at' => now()->subMinute(),
        ]);

        return AdvanceSettlementItem::factory()->create([
            'advance_settlement_id' => $batch->id,
            'category_id' => $category->id,
            'amount' => $amount,
            'review_status' => 'accepted',
            'submitted_by' => $submitter->id,
        ]);
    }

    private function submitBatch(OperationalAdvance $advance, User $recipient, Category $category, string $amount): AdvanceSettlement
    {
        $this->actingAs($recipient)->post(route('operational-advances.settlements.store', $advance), [
            'submission_key' => (string) Str::uuid7(),
            'items' => [[
                'occurred_at' => now()->subHour()->format('Y-m-d\\TH:i'),
                'description' => 'Pengeluaran susulan',
                'category_id' => $category->id,
                'amount' => $amount,
                'attachments' => [UploadedFile::fake()->createWithContent('bukti.pdf', "%PDF-1.4\nBukti")],
            ]],
        ])->assertRedirect();

        return AdvanceSettlement::query()->where('operational_advance_id', $advance->id)->latest('batch_number')->firstOrFail()->load('items');
    }

    private function reviewItem(OperationalAdvance $advance, AdvanceSettlement $batch, AdvanceSettlementItem $item, User $finance): void
    {
        $this->actingAs($finance)->post(route('operational-advances.settlement-items.review', [$advance, $batch, $item]), ['decision' => 'accepted'])
            ->assertRedirect();
    }

    /** @return array<string, string> */
    private function returnPayload(string $key, string $amount): array
    {
        return [
            'advance_event_key' => $key,
            'amount' => $amount,
            'occurred_at' => now()->subHour()->format('Y-m-d\\TH:i'),
        ];
    }

    /** @return array<string, mixed> */
    private function repaymentPayload(string $key, CashAccount $account, string $amount): array
    {
        return [
            'advance_event_key' => $key,
            'cash_account_id' => $account->id,
            'amount' => $amount,
            'occurred_at' => now()->subHour()->format('Y-m-d\\TH:i'),
        ];
    }

    private function createOpeningMovement(CashAccount $account, User $actor, string $amount): void
    {
        CashMovement::factory()->create([
            'cash_account_id' => $account->id,
            'kind' => 'opening_balance',
            'direction' => 'in',
            'amount' => $amount,
            'idempotency_key' => 'advance-repayment-opening:'.$account->id,
            'created_by' => $actor->id,
        ]);
    }

    private function userWithRole(string $roleName): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('name', $roleName)->firstOrFail());

        return $user;
    }
}
