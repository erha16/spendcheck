<?php

namespace Tests\Feature;

use App\Models\CashAccount;
use App\Models\OpeningBalanceRequest;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OpeningBalanceWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
    }

    public function test_pending_opening_balance_does_not_change_account_balance(): void
    {
        $financeUser = $this->userWithRole('keuangan_sarpras');
        $cashAccount = CashAccount::factory()->create();

        $response = $this->actingAs($financeUser)->postJson(route('cash-accounts.opening-balances.store'), [
            'cash_account_id' => $cashAccount->id,
            'amount' => '250000.75',
            'effective_at' => '2026-01-01 00:00:00',
            'reason' => 'Saldo per awal periode.',
        ]);

        $response->assertCreated()->assertJsonPath('data.status', 'pending');
        $this->assertSame('0.00', $cashAccount->ledgerBalance());
        $this->assertDatabaseCount('cash_movements', 0);
        $this->assertDatabaseHas('audit_logs', ['action' => 'opening_balance_proposed']);
    }

    public function test_approval_creates_exactly_one_movement_even_when_repeated(): void
    {
        $financeUser = $this->userWithRole('keuangan_sarpras');
        $headUser = $this->userWithRole('kepala_sarpras');
        $cashAccount = CashAccount::factory()->create();
        $openingBalanceRequest = OpeningBalanceRequest::factory()->create([
            'cash_account_id' => $cashAccount->id,
            'amount' => '900.10',
            'proposed_by' => $financeUser->id,
        ]);
        $route = route('cash-accounts.opening-balances.review', $openingBalanceRequest->public_id);

        $this->actingAs($headUser)->postJson($route, ['decision' => 'approved', 'review_notes' => 'Diverifikasi.'])->assertOk();
        $this->postJson($route, ['decision' => 'approved'])->assertOk();

        $this->assertSame('approved', $openingBalanceRequest->fresh()->status);
        $this->assertSame($headUser->id, $openingBalanceRequest->fresh()->reviewed_by);
        $this->assertSame('900.10', $cashAccount->ledgerBalance());
        $this->assertDatabaseCount('cash_movements', 1);
        $this->assertDatabaseCount('audit_logs', 1);
        $this->assertDatabaseHas('audit_logs', ['action' => 'opening_balance_approved']);
    }

    public function test_rejected_opening_balance_has_no_ledger_effect(): void
    {
        $financeUser = $this->userWithRole('keuangan_sarpras');
        $headUser = $this->userWithRole('kepala_sarpras');
        $openingBalanceRequest = OpeningBalanceRequest::factory()->create(['proposed_by' => $financeUser->id]);

        $this->actingAs($headUser)->postJson(route('cash-accounts.opening-balances.review', $openingBalanceRequest->public_id), [
            'decision' => 'rejected',
            'review_notes' => 'Bukti perlu diperbaiki.',
        ])->assertOk()->assertJsonPath('data.status', 'rejected');

        $this->assertDatabaseCount('cash_movements', 0);
        $this->assertDatabaseHas('audit_logs', ['action' => 'opening_balance_rejected']);
    }

    public function test_opening_balance_proposer_cannot_approve_their_own_request(): void
    {
        $user = $this->userWithRole('keuangan_sarpras', 'kepala_sarpras');
        $openingBalanceRequest = OpeningBalanceRequest::factory()->create(['proposed_by' => $user->id]);

        $this->actingAs($user)->postJson(route('cash-accounts.opening-balances.review', $openingBalanceRequest->public_id), [
            'decision' => 'approved',
        ])->assertUnprocessable()->assertJsonValidationErrors('reviewer');

        $this->assertSame('pending', $openingBalanceRequest->fresh()->status);
        $this->assertDatabaseCount('cash_movements', 0);
    }

    public function test_invalid_opening_balance_amount_is_rejected(): void
    {
        $financeUser = $this->userWithRole('keuangan_sarpras');
        $cashAccount = CashAccount::factory()->create();

        $this->actingAs($financeUser)->postJson(route('cash-accounts.opening-balances.store'), [
            'cash_account_id' => $cashAccount->id,
            'amount' => '0.00',
            'effective_at' => '2026-01-01',
            'reason' => 'Saldo awal.',
        ])->assertUnprocessable()->assertJsonValidationErrors('amount');

        $this->assertDatabaseCount('opening_balance_requests', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_non_head_cannot_review_opening_balance(): void
    {
        $financeUser = $this->userWithRole('keuangan_sarpras');
        $openingBalanceRequest = OpeningBalanceRequest::factory()->create(['proposed_by' => $financeUser->id]);

        $this->actingAs($financeUser)->postJson(route('cash-accounts.opening-balances.review', $openingBalanceRequest->public_id), [
            'decision' => 'approved',
        ])->assertForbidden();

        $this->assertSame('pending', $openingBalanceRequest->fresh()->status);
    }

    private function userWithRole(string ...$roles): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->whereIn('name', $roles)->get());

        return $user;
    }
}
