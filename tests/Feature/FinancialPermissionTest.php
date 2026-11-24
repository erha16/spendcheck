<?php

namespace Tests\Feature;

use App\Models\CashAccount;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FinancialPermissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
    }

    public static function rolePermissionMatrix(): array
    {
        return [
            'system admin has no finance rights' => ['admin_system', false, false, false, false, false, false, false, false, false, false, false],
            'petugas creates expense only' => ['petugas_sarpras', true, false, true, false, false, false, false, false, false, false, false],
            'driver has no phase 3a rights' => ['driver', false, false, false, false, false, false, false, false, false, false, false],
            'finance manages transactions and can override' => ['keuangan_sarpras', true, true, true, true, true, true, true, true, false, true, true],
            'head is read only and approves opening balance' => ['kepala_sarpras', true, true, false, false, false, false, false, false, true, false, false],
            'finance division is read only' => ['divisi_keuangan', true, true, false, false, false, false, false, false, false, false, false],
        ];
    }

    #[DataProvider('rolePermissionMatrix')]
    public function test_role_receives_only_its_phase_three_finance_permissions(
        string $roleName,
        bool $canViewTransactions,
        bool $canViewCashflow,
        bool $canCreateExpense,
        bool $canCreateIncome,
        bool $canCreateTransfer,
        bool $canManage,
        bool $canReview,
        bool $canPost,
        bool $canApproveOpeningBalance,
        bool $canOverrideNegativeBalance,
        bool $canProposeOpeningBalance,
    ): void {
        $user = $this->userWithRole($roleName);

        $this->assertSame($canViewTransactions, $user->hasPermission('transactions.view'));
        $this->assertSame($canViewCashflow, $user->hasPermission('cashflow.view'));
        $this->assertSame($canCreateExpense, $user->hasPermission('transactions.expense.create'));
        $this->assertSame($canCreateIncome, $user->hasPermission('transactions.income.create'));
        $this->assertSame($canCreateTransfer, $user->hasPermission('transactions.transfer.create'));
        $this->assertSame($canManage, $user->hasPermission('transactions.manage'));
        $this->assertSame($canReview, $user->hasPermission('transactions.review'));
        $this->assertSame($canPost, $user->hasPermission('transactions.post'));
        $this->assertSame($canApproveOpeningBalance, $user->hasPermission('cash_accounts.opening_balance.approve'));
        $this->assertSame($canOverrideNegativeBalance, $user->hasPermission('transactions.negative_balance.override'));
        $this->assertSame($canProposeOpeningBalance, $user->hasPermission('cash_accounts.opening_balance.propose'));
    }

    public function test_petugas_can_only_view_transactions_they_created(): void
    {
        $petugas = $this->userWithRole('petugas_sarpras');
        $ownTransaction = Transaction::factory()->create(['created_by' => $petugas->id, 'number' => 'EXP/202605/9001']);
        Transaction::factory()->create(['number' => 'EXP/202605/9002']);

        $this->actingAs($petugas)->get(route('transactions.index'))
            ->assertOk()
            ->assertSee($ownTransaction->number)
            ->assertDontSee('EXP/202605/9002');
    }

    public function test_system_admin_cannot_view_transaction_or_cashflow_endpoints(): void
    {
        $admin = $this->userWithRole('admin_system');

        $this->actingAs($admin)->getJson(route('transactions.index'))->assertForbidden();
        $this->getJson(route('cashflow.index'))->assertForbidden();
        $this->get(route('transactions.create', ['type' => 'transfer']))->assertForbidden();
    }

    public function test_divisi_keuangan_can_read_but_cannot_propose_opening_balance(): void
    {
        $financeDivision = $this->userWithRole('divisi_keuangan');
        $cashAccount = CashAccount::factory()->create();

        $this->actingAs($financeDivision)->get(route('transactions.index'))->assertOk();
        $this->get(route('cashflow.index'))->assertOk();
        $this->postJson(route('cash-accounts.opening-balances.store'), [
            'cash_account_id' => $cashAccount->id,
            'amount' => '100.00',
            'effective_at' => '2026-01-01',
            'reason' => 'Saldo awal.',
        ])->assertForbidden();
    }

    public function test_finance_division_cannot_create_transactions(): void
    {
        $financeDivision = $this->userWithRole('divisi_keuangan');

        $this->actingAs($financeDivision)->get(route('transactions.create', ['type' => 'income']))->assertForbidden();
        $this->get(route('transactions.create', ['type' => 'transfer']))->assertForbidden();
        $this->post(route('transactions.store'), ['type' => 'income'])->assertForbidden();
        $this->post(route('transactions.store'), ['type' => 'transfer'])->assertForbidden();
    }

    public function test_only_keuangan_sarpras_has_transaction_correction_permission(): void
    {
        foreach (['admin_system', 'petugas_sarpras', 'driver', 'kepala_sarpras', 'divisi_keuangan'] as $roleName) {
            $this->assertFalse($this->userWithRole($roleName)->hasPermission('transactions.correct'));
        }

        $this->assertTrue($this->userWithRole('keuangan_sarpras')->hasPermission('transactions.correct'));
    }

    public function test_multiple_roles_combine_permissions_and_permission_revocation_is_immediate(): void
    {
        $user = $this->userWithRole('keuangan_sarpras');
        $user->roles()->attach(Role::query()->where('name', 'divisi_keuangan')->firstOrFail());
        $transaction = Transaction::factory()->create(['created_by' => $user->id, 'status' => 'posted']);

        $this->assertTrue($user->hasPermission('transactions.correct'));
        $this->assertTrue($user->hasPermission('cashflow.view'));

        $financeRole = Role::query()->where('name', 'keuangan_sarpras')->firstOrFail();
        $correctionPermission = Permission::query()->where('name', 'transactions.correct')->firstOrFail();
        $financeRole->permissions()->detach($correctionPermission->id);

        $this->assertFalse($user->hasPermission('transactions.correct'));
        $this->actingAs($user)->get(route('transactions.corrections.create', $transaction))->assertForbidden();
        $this->get(route('cashflow.index'))->assertOk();
    }

    private function userWithRole(string $roleName): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('name', $roleName)->firstOrFail());

        return $user;
    }
}
