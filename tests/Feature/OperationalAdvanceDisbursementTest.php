<?php

namespace Tests\Feature;

use App\Models\CashAccount;
use App\Models\CashMovement;
use App\Models\OperationalAdvance;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\User;
use App\Services\OperationalAdvanceDisbursementService;
use App\Services\TransactionNumberGenerator;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OperationalAdvanceDisbursementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
    }

    public function test_finance_disburses_the_approved_amount_as_one_linked_cash_outflow_without_expense_items(): void
    {
        [$advance, $finance] = $this->approvedAdvance();
        $account = CashAccount::factory()->create();
        $this->createOpeningMovement($account, $finance, '800.00');

        $this->actingAs($finance)->post(route('operational-advances.disburse', $advance), [
            'cash_account_id' => $account->id,
            'occurred_at' => now()->subMinute()->format('Y-m-d\TH:i'),
            'amount' => '1.00',
        ])->assertRedirect(route('operational-advances.show', $advance));

        $advance->refresh();
        $transaction = Transaction::query()->where('operational_advance_id', $advance->id)->firstOrFail();
        $movement = CashMovement::query()->where('transaction_id', $transaction->id)->firstOrFail();

        $this->assertSame('disbursed', $advance->status);
        $this->assertSame('500.00', $advance->disbursed_amount);
        $this->assertSame($finance->id, $advance->disbursed_by);
        $this->assertNotNull($advance->disbursed_at);
        $this->assertSame('advance_payout', $transaction->type);
        $this->assertStringStartsWith('BPD/', $transaction->number);
        $this->assertSame('500.00', $transaction->total_amount);
        $this->assertSame('posted', $transaction->status);
        $this->assertSame($finance->id, $transaction->posted_by);
        $this->assertSame($advance->id, $transaction->operational_advance_id);
        $this->assertSame('advance_payout', $movement->kind);
        $this->assertSame('out', $movement->direction);
        $this->assertSame('500.00', $movement->amount);
        $this->assertSame($account->id, $movement->cash_account_id);
        $this->assertSame('300.00', $account->fresh()->ledgerBalance());
        $this->assertDatabaseCount('transaction_items', 0);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'operational_advance_disbursed',
            'subject_id' => $advance->id,
        ]);
        $this->assertDatabaseCount('cash_movements', 2);
    }

    public function test_disbursement_date_and_cashflow_entry_are_displayed_separately_from_expense(): void
    {
        [$advance, $finance] = $this->approvedAdvance();
        $account = CashAccount::factory()->create();
        $this->createOpeningMovement($account, $finance, '700.00');
        $occurredAt = now()->subDay()->startOfMinute();

        $this->actingAs($finance)->post(route('operational-advances.disburse', $advance), [
            'cash_account_id' => $account->id,
            'occurred_at' => $occurredAt->format('Y-m-d\TH:i'),
        ])->assertRedirect();

        $transaction = Transaction::query()->where('operational_advance_id', $advance->id)->firstOrFail();
        $this->get(route('operational-advances.show', $advance))
            ->assertOk()
            ->assertSee('Informasi pencairan')
            ->assertSee($transaction->number)
            ->assertSee($account->name);

        $this->get(route('cashflow.index', ['movement_kinds' => ['advance_payout']]))
            ->assertOk()
            ->assertSee($transaction->number)
            ->assertSee('Pencairan bon');

        $this->assertSame($occurredAt->format('Y-m-d H:i:s'), $transaction->fresh()->occurred_at->format('Y-m-d H:i:s'));
        $this->assertDatabaseCount('transaction_items', 0);
    }

    public function test_disbursement_uses_the_approved_amount_and_cannot_be_repeated(): void
    {
        [$advance, $finance] = $this->approvedAdvance();
        $account = CashAccount::factory()->create();
        $this->createOpeningMovement($account, $finance, '800.00');
        $payload = ['cash_account_id' => $account->id, 'occurred_at' => now()->subMinute()->format('Y-m-d\TH:i')];

        $this->actingAs($finance)->post(route('operational-advances.disburse', $advance), $payload)->assertRedirect();
        $this->post(route('operational-advances.disburse', $advance), $payload)->assertSessionHasErrors('operational_advance');

        $this->assertDatabaseCount('transactions', 1);
        $this->assertDatabaseCount('cash_movements', 2);
    }

    public function test_unapproved_rejected_and_cancelled_advances_cannot_be_disbursed(): void
    {
        $finance = $this->userWithRole('keuangan_sarpras');
        $requester = $this->userWithRole('driver');
        $account = CashAccount::factory()->create();

        foreach (['draft', 'submitted', 'rejected', 'cancelled'] as $status) {
            $advance = $this->advanceFor($requester, $finance, $status);
            $this->actingAs($finance)->post(route('operational-advances.disburse', $advance), [
                'cash_account_id' => $account->id,
                'occurred_at' => now()->subMinute()->format('Y-m-d\TH:i'),
            ])->assertSessionHasErrors('operational_advance');
        }

        $this->assertDatabaseCount('transactions', 0);
        $this->assertDatabaseCount('cash_movements', 0);
    }

    public function test_inactive_cash_account_is_rejected(): void
    {
        [$advance, $finance] = $this->approvedAdvance();
        $account = CashAccount::factory()->create(['is_active' => false]);

        $this->actingAs($finance)->post(route('operational-advances.disburse', $advance), [
            'cash_account_id' => $account->id,
            'occurred_at' => now()->subMinute()->format('Y-m-d\TH:i'),
        ])->assertSessionHasErrors('cash_account_id');

        $this->assertSame('approved', $advance->fresh()->status);
        $this->assertDatabaseCount('transactions', 0);
        $this->assertDatabaseCount('cash_movements', 0);
    }

    public function test_insufficient_balance_is_rejected_without_negative_balance_override(): void
    {
        [$advance, $finance] = $this->approvedAdvance();
        $account = CashAccount::factory()->create();
        $this->createOpeningMovement($account, $finance, '100.00');

        $this->actingAs($finance)->post(route('operational-advances.disburse', $advance), [
            'cash_account_id' => $account->id,
            'occurred_at' => now()->subMinute()->format('Y-m-d\TH:i'),
        ])->assertSessionHasErrors('cash_account_id');

        $this->assertSame('100.00', $account->fresh()->ledgerBalance());
        $this->assertSame('approved', $advance->fresh()->status);
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_phase_three_negative_balance_override_requires_permission_and_reason(): void
    {
        [$advance, $finance] = $this->approvedAdvance();
        $account = CashAccount::factory()->create();

        $this->actingAs($finance)->post(route('operational-advances.disburse', $advance), [
            'cash_account_id' => $account->id,
            'occurred_at' => now()->subMinute()->format('Y-m-d\TH:i'),
            'allow_negative' => '1',
        ])->assertSessionHasErrors('override_reason');

        $this->post(route('operational-advances.disburse', $advance), [
            'cash_account_id' => $account->id,
            'occurred_at' => now()->subMinute()->format('Y-m-d\TH:i'),
            'allow_negative' => '1',
            'override_reason' => 'Persetujuan pimpinan untuk kondisi kas sementara.',
        ])->assertRedirect();

        $this->assertSame('-500.00', $account->fresh()->ledgerBalance());
        $this->assertDatabaseHas('transactions', [
            'operational_advance_id' => $advance->id,
            'negative_balance_override' => true,
            'negative_balance_override_by' => $finance->id,
        ]);
    }

    public function test_only_finance_without_a_benefit_in_the_advance_can_disburse(): void
    {
        [$advance] = $this->approvedAdvance();
        $account = CashAccount::factory()->create();
        $this->createOpeningMovement($account, $this->userWithRole('keuangan_sarpras'), '800.00');

        foreach (['petugas_sarpras', 'driver', 'kepala_sarpras', 'divisi_keuangan', 'admin_system'] as $role) {
            $user = $this->userWithRole($role);
            $this->actingAs($user)->post(route('operational-advances.disburse', $advance), [
                'cash_account_id' => $account->id,
                'occurred_at' => now()->subMinute()->format('Y-m-d\TH:i'),
            ])->assertForbidden();
        }

        $this->assertSame('approved', $advance->fresh()->status);
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_finance_requester_or_recipient_cannot_disburse_their_own_advance(): void
    {
        $finance = $this->userWithRole('keuangan_sarpras');
        $advance = $this->advanceFor($finance, $finance, 'approved');
        $account = CashAccount::factory()->create();

        $this->actingAs($finance)->post(route('operational-advances.disburse', $advance), [
            'cash_account_id' => $account->id,
            'occurred_at' => now()->subMinute()->format('Y-m-d\TH:i'),
        ])->assertForbidden();

        $this->assertSame('approved', $advance->fresh()->status);
    }

    public function test_transaction_creation_failure_rolls_back_advance_and_sequence_changes(): void
    {
        [$advance, $finance] = $this->approvedAdvance();
        $account = CashAccount::factory()->create();
        $this->createOpeningMovement($account, $finance, '800.00');
        $occurredAt = now()->subMinute();
        $period = $occurredAt->format('Ym');

        $this->mock(TransactionNumberGenerator::class, function ($mock): void {
            $mock->shouldReceive('next')->once()->andThrow(new \RuntimeException('Nomor transaksi gagal dibuat.'));
        });

        try {
            app(OperationalAdvanceDisbursementService::class)->disburse($advance, $finance, $account->id, $occurredAt, false, null);
            $this->fail('The transaction creation failure should be re-thrown.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Nomor transaksi gagal dibuat.', $exception->getMessage());
        }

        $this->assertSame('approved', $advance->fresh()->status);
        $this->assertNull($advance->fresh()->disbursed_amount);
        $this->assertDatabaseCount('transactions', 0);
        $this->assertDatabaseCount('cash_movements', 1);
        $this->assertDatabaseMissing('transaction_number_sequences', ['type' => 'advance_payout', 'period' => $period]);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'operational_advance_disbursed', 'subject_id' => $advance->id]);
    }

    public function test_cash_movement_failure_rolls_back_created_transaction_and_advance(): void
    {
        [$advance, $finance] = $this->approvedAdvance();
        $account = CashAccount::factory()->create();
        $this->createOpeningMovement($account, $finance, '800.00');
        $idempotencyKey = 'advance-payout:'.$advance->public_id;
        CashMovement::factory()->create([
            'cash_account_id' => $account->id,
            'transaction_id' => null,
            'kind' => 'opening_balance',
            'direction' => 'in',
            'amount' => '1.00',
            'idempotency_key' => $idempotencyKey,
            'created_by' => $finance->id,
        ]);

        try {
            app(OperationalAdvanceDisbursementService::class)->disburse(
                $advance,
                $finance,
                $account->id,
                now()->subMinute(),
                false,
                null,
            );
            $this->fail('A duplicate idempotency key must fail to insert a second movement.');
        } catch (QueryException) {
            $this->assertSame('approved', $advance->fresh()->status);
            $this->assertNull($advance->fresh()->disbursed_amount);
            $this->assertDatabaseCount('transactions', 0);
            $this->assertDatabaseCount('cash_movements', 2);
            $this->assertDatabaseMissing('audit_logs', ['action' => 'operational_advance_disbursed', 'subject_id' => $advance->id]);
        }
    }

    public function test_idempotency_key_is_unique_for_the_advance_payout(): void
    {
        [$advance, $finance] = $this->approvedAdvance();
        $account = CashAccount::factory()->create();

        $this->createOpeningMovement($account, $finance, '800.00');
        $this->actingAs($finance)->post(route('operational-advances.disburse', $advance), [
            'cash_account_id' => $account->id,
            'occurred_at' => now()->subMinute()->format('Y-m-d\TH:i'),
        ])->assertRedirect();

        $this->assertDatabaseHas('cash_movements', ['idempotency_key' => 'advance-payout:'.$advance->public_id]);

        $this->expectException(QueryException::class);
        CashMovement::create([
            'cash_account_id' => $account->id,
            'kind' => 'advance_payout',
            'direction' => 'out',
            'amount' => '500.00',
            'idempotency_key' => 'advance-payout:'.$advance->public_id,
            'occurred_at' => now()->subMinute(),
            'recorded_at' => now(),
            'created_by' => $finance->id,
        ]);
    }

    public function test_payout_correction_reverses_the_original_and_posts_one_replacement_without_expense_items(): void
    {
        [$advance, $finance] = $this->approvedAdvance();
        $originalAccount = CashAccount::factory()->create();
        $replacementAccount = CashAccount::factory()->create();
        $this->createOpeningMovement($originalAccount, $finance, '800.00');
        $this->createOpeningMovement($replacementAccount, $finance, '1000.00');

        $this->actingAs($finance)->post(route('operational-advances.disburse', $advance), [
            'cash_account_id' => $originalAccount->id,
            'occurred_at' => now()->subDay()->format('Y-m-d\\TH:i'),
        ])->assertRedirect();

        $original = Transaction::query()->where('operational_advance_id', $advance->id)->firstOrFail();
        $this->get(route('operational-advances.show', $advance))
            ->assertOk()
            ->assertSee('Koreksi pencairan');
        $this->get(route('transactions.show', $original))
            ->assertOk()
            ->assertDontSee('Koreksi transaksi');
        $this->get(route('transactions.corrections.create', $original))->assertNotFound();

        $this->post(route('operational-advances.payout-correction', $advance), [
            'cash_account_id' => $replacementAccount->id,
            'occurred_at' => now()->subHours(12)->format('Y-m-d\\TH:i'),
            'correction_reason' => 'Rekening pencairan awal salah.',
        ])->assertRedirect(route('operational-advances.show', $advance));

        $replacement = Transaction::query()->where('reversal_of_id', $original->id)->firstOrFail();
        $this->assertSame('reversed', $original->fresh()->status);
        $this->assertSame('posted', $replacement->status);
        $this->assertSame('advance_payout', $replacement->type);
        $this->assertSame($advance->id, $replacement->operational_advance_id);
        $this->assertSame('500.00', $replacement->total_amount);
        $this->assertSame('800.00', $originalAccount->fresh()->ledgerBalance());
        $this->assertSame('500.00', $replacementAccount->fresh()->ledgerBalance());
        $this->assertDatabaseHas('cash_movements', [
            'transaction_id' => $original->id,
            'kind' => 'reversal',
            'direction' => 'in',
            'amount' => '500.00',
        ]);
        $this->assertDatabaseHas('cash_movements', [
            'transaction_id' => $replacement->id,
            'kind' => 'advance_payout',
            'direction' => 'out',
            'amount' => '500.00',
        ]);
        $this->assertDatabaseHas('transaction_revisions', [
            'transaction_id' => $original->id,
            'replacement_transaction_id' => $replacement->id,
            'reason' => 'Rekening pencairan awal salah.',
        ]);
        $this->assertSame($replacement->id, $advance->fresh()->payoutTransaction->id);
        $this->assertDatabaseCount('transaction_items', 0);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'operational_advance_payout_corrected',
            'subject_id' => $advance->id,
        ]);
        $this->assertDatabaseCount('transaction_revisions', 1);
    }

    public function test_only_finance_with_correction_permission_can_correct_a_payout(): void
    {
        [$advance, $finance] = $this->approvedAdvance();
        $account = CashAccount::factory()->create();
        $this->createOpeningMovement($account, $finance, '800.00');
        $this->actingAs($finance)->post(route('operational-advances.disburse', $advance), [
            'cash_account_id' => $account->id,
            'occurred_at' => now()->subMinute()->format('Y-m-d\\TH:i'),
        ])->assertRedirect();

        foreach (['driver', 'petugas_sarpras', 'kepala_sarpras', 'divisi_keuangan', 'admin_system'] as $role) {
            $this->actingAs($this->userWithRole($role))->post(route('operational-advances.payout-correction', $advance), [
                'cash_account_id' => $account->id,
                'occurred_at' => now()->subMinute()->format('Y-m-d\\TH:i'),
                'correction_reason' => 'Perlu koreksi alasan.',
            ])->assertForbidden();
        }

        $this->assertDatabaseCount('transaction_revisions', 0);
        $this->assertSame('posted', $advance->payoutTransaction->status);
    }

    /** @return array{OperationalAdvance, User} */
    private function approvedAdvance(): array
    {
        $requester = $this->userWithRole('driver');
        $finance = $this->userWithRole('keuangan_sarpras');

        return [$this->advanceFor($requester, $finance, 'approved'), $finance];
    }

    private function advanceFor(User $requester, User $approver, string $status): OperationalAdvance
    {
        return OperationalAdvance::factory()->create([
            'requester_user_id' => $requester->id,
            'responsible_user_id' => $requester->id,
            'created_by' => $requester->id,
            'status' => $status,
            'approved_amount' => $status === 'approved' ? '500.00' : null,
            'approved_by' => $status === 'approved' ? $approver->id : null,
            'approved_at' => $status === 'approved' ? now()->subMinute() : null,
        ]);
    }

    private function createOpeningMovement(CashAccount $account, User $actor, string $amount): void
    {
        CashMovement::factory()->create([
            'cash_account_id' => $account->id,
            'kind' => 'opening_balance',
            'direction' => 'in',
            'amount' => $amount,
            'idempotency_key' => 'advance-payout-test-opening:'.$account->id,
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
