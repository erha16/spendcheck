<?php

namespace Tests\Feature;

use App\Models\CashAccount;
use App\Models\CashMovement;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CashflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
    }

    public function test_cashflow_page_shows_account_balances_and_separates_transfer_movements(): void
    {
        $finance = $this->userWithRole('keuangan_sarpras');
        $source = CashAccount::factory()->create(['name' => 'Kas Sumber']);
        $destination = CashAccount::factory()->create(['name' => 'Kas Tujuan']);
        $transfer = $this->transferTransaction($finance, $source, $destination, '2026-05-10 10:00:00', '40.00');
        $this->movement($finance, $source, $transfer, 'transfer_out', 'out', '40.00', '2026-05-10 10:00:00', 'out');
        $this->movement($finance, $destination, $transfer, 'transfer_in', 'in', '40.00', '2026-05-10 10:00:00', 'in');

        $response = $this->actingAs($finance)->get(route('cashflow.index'));

        $response->assertOk()
            ->assertSee('Kas Sumber')
            ->assertSee('Transfer keluar')
            ->assertSee('Transfer masuk')
            ->assertSee('TRF/202605/0001');
        $this->assertSame('-40.00', $source->ledgerBalance());
        $this->assertSame('40.00', $destination->ledgerBalance());
    }

    public function test_cashflow_filter_groups_combine_with_and_and_values_within_each_group_combine_with_or(): void
    {
        $finance = $this->userWithRole('keuangan_sarpras');
        $firstAccount = CashAccount::factory()->create(['name' => 'Kas Pertama']);
        $secondAccount = CashAccount::factory()->create(['name' => 'Kas Kedua']);
        $thirdAccount = CashAccount::factory()->create(['name' => 'Kas Ketiga']);
        $firstTransfer = $this->transferTransaction($finance, $thirdAccount, $firstAccount, '2026-05-12 10:00:00', '10.00');
        $secondTransfer = $this->transferTransaction($finance, $secondAccount, $thirdAccount, '2026-05-13 10:00:00', '20.00');
        $this->movement($finance, $firstAccount, $firstTransfer, 'transfer_in', 'in', '10.00', '2026-05-12 10:00:00', 'in');
        $this->movement($finance, $secondAccount, $secondTransfer, 'transfer_out', 'out', '20.00', '2026-05-13 10:00:00', 'out');
        $this->movement($finance, $thirdAccount, null, 'income', 'in', '500.00', '2026-05-13 10:00:00');
        $url = route('cashflow.index').'?date_from=2026-05-11&date_to=2026-05-14'
            .'&cash_account_ids%5B0%5D='.$firstAccount->id.'&cash_account_ids%5B1%5D='.$secondAccount->id
            .'&movement_kinds%5B0%5D=transfer_in&movement_kinds%5B1%5D=transfer_out&search=TRF%2F202605';

        $response = $this->actingAs($finance)->get($url);

        $response->assertOk()->assertSee($firstTransfer->number)->assertSee($secondTransfer->number);
        $movements = $response->viewData('movements');
        $this->assertSame(2, $movements->total());
        $this->assertSame(['transfer_in', 'transfer_out'], $movements->getCollection()->pluck('kind')->sort()->values()->all());
        $this->assertSame(['Kas Kedua', 'Kas Pertama'], $movements->getCollection()->pluck('cash_account_name')->sort()->values()->all());
    }

    public function test_running_balance_on_second_page_includes_movements_from_before_that_page(): void
    {
        $finance = $this->userWithRole('keuangan_sarpras');
        $account = CashAccount::factory()->create();
        $this->movement($finance, $account, null, 'opening_balance', 'in', '1000.00', '2026-05-01 08:00:00');
        for ($movementNumber = 1; $movementNumber <= 20; $movementNumber++) {
            $day = str_pad((string) ($movementNumber + 1), 2, '0', STR_PAD_LEFT);
            $this->movement($finance, $account, null, 'income', 'in', '1.00', '2026-05-'.$day.' 08:00:00');
        }

        $pageOne = $this->actingAs($finance)->get(route('cashflow.index'));
        $pageOne->assertOk();
        $this->assertSame(1020.0, (float) $pageOne->viewData('movements')->first()->running_balance);

        $pageTwo = $this->get(route('cashflow.index', ['page' => 2]));
        $pageTwo->assertOk();
        $this->assertSame(1000.0, (float) $pageTwo->viewData('movements')->first()->running_balance);
        $this->assertSame('1020.00', $account->ledgerBalance());
    }

    public function test_date_filter_does_not_reset_the_running_balance_to_the_filtered_period(): void
    {
        $finance = $this->userWithRole('keuangan_sarpras');
        $account = CashAccount::factory()->create();
        $this->movement($finance, $account, null, 'opening_balance', 'in', '250.00', '2026-05-01 08:00:00');
        $this->movement($finance, $account, null, 'expense', 'out', '30.00', '2026-05-12 08:00:00');

        $response = $this->actingAs($finance)->get(route('cashflow.index', ['date_from' => '2026-05-12']));

        $response->assertOk()->assertSee('Saldo berjalan');
        $this->assertSame(220.0, (float) $response->viewData('movements')->first()->running_balance);
    }

    public function test_finance_json_cashflow_exposes_only_account_balance_fields(): void
    {
        $finance = $this->userWithRole('keuangan_sarpras');
        $account = CashAccount::factory()->create(['name' => 'Kas JSON']);
        $this->movement($finance, $account, null, 'opening_balance', 'in', '25.00', '2026-05-01 08:00:00');

        $this->actingAs($finance)->getJson(route('cashflow.index'))->assertOk()->assertJson([
            ['id' => $account->id, 'name' => 'Kas JSON', 'balance' => '25.00'],
        ]);
    }

    private function transferTransaction(User $creator, CashAccount $source, CashAccount $destination, string $occurredAt, string $amount): Transaction
    {
        return Transaction::factory()->create([
            'type' => 'transfer',
            'occurred_at' => $occurredAt,
            'description' => 'Transfer untuk cashflow.',
            'responsible_user_id' => null,
            'payment_method_id' => null,
            'source_cash_account_id' => $source->id,
            'destination_cash_account_id' => $destination->id,
            'total_amount' => $amount,
            'status' => 'posted',
            'created_by' => $creator->id,
        ]);
    }

    private function movement(
        User $creator,
        CashAccount $account,
        ?Transaction $transaction,
        string $kind,
        string $direction,
        string $amount,
        string $occurredAt,
        ?string $transferLeg = null,
    ): CashMovement {
        return CashMovement::create([
            'cash_account_id' => $account->id,
            'transaction_id' => $transaction?->id,
            'kind' => $kind,
            'direction' => $direction,
            'transfer_leg' => $transferLeg,
            'amount' => $amount,
            'idempotency_key' => fake()->unique()->uuid(),
            'occurred_at' => $occurredAt,
            'recorded_at' => $occurredAt,
            'created_by' => $creator->id,
        ]);
    }

    private function userWithRole(string $roleName): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('name', $roleName)->firstOrFail());

        return $user;
    }
}
