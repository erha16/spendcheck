<?php

namespace Tests\Feature;

use App\Models\CashAccount;
use App\Models\CashMovement;
use App\Models\OperationalAdvance;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\User;
use App\Services\OperationalAdvanceDisbursementService;
use App\Services\OperationalAdvanceReconciliationService;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class MySqlAdvanceCashEventWorkflowTest extends TestCase
{
    public function test_two_mysql_connections_cannot_receive_the_same_return_twice(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Tes row lock harus dijalankan pada MySQL.');
        }

        $primaryConnection = DB::connection('mysql');
        if (! str_ends_with($primaryConnection->getDatabaseName(), '_testing')) {
            $this->markTestSkipped('Tes locking hanya dijalankan pada database MySQL terpisah dengan suffix _testing.');
        }

        config(['database.connections.mysql_advance_event_lock_test' => config('database.connections.mysql')]);
        DB::purge('mysql_advance_event_lock_test');
        DB::setDefaultConnection('mysql');
        $this->seed(AccessControlSeeder::class);

        $recipient = $this->userWithRole('driver');
        $finance = $this->userWithRole('keuangan_sarpras');
        $advance = OperationalAdvance::factory()->create([
            'requester_user_id' => $recipient->id,
            'responsible_user_id' => $recipient->id,
            'created_by' => $recipient->id,
            'status' => 'approved',
            'approved_amount' => '500.00',
            'approved_by' => $finance->id,
            'approved_at' => now()->subMinute(),
        ]);
        $account = CashAccount::factory()->create();
        $openingKey = 'mysql-advance-return-opening-'.$advance->public_id;
        CashMovement::create([
            'cash_account_id' => $account->id,
            'kind' => 'opening_balance',
            'direction' => 'in',
            'amount' => '800.00',
            'idempotency_key' => $openingKey,
            'occurred_at' => now()->subDay(),
            'recorded_at' => now(),
            'created_by' => $finance->id,
        ]);

        $lockTestConnection = DB::connection('mysql_advance_event_lock_test');
        $advancePeriod = $advance->created_at->format('Ym');
        $transactionPeriod = now()->format('Ym');
        $return = null;

        try {
            $disbursementAt = now()->subMinutes(2);
            app(OperationalAdvanceDisbursementService::class)->disburse($advance, $finance, $account->id, $disbursementAt);
            $return = app(OperationalAdvanceReconciliationService::class)->submitReturn($advance->fresh(), $recipient, [
                'advance_event_key' => (string) Str::uuid7(),
                'amount' => '500.00',
                'occurred_at' => now()->subMinute(),
                'notes' => 'Pengembalian sisa dana.',
            ]);

            $primaryConnection->beginTransaction();
            $primaryConnection->table('operational_advances')->where('id', $advance->id)->lockForUpdate()->first();
            $lockTestConnection->statement('SET SESSION innodb_lock_wait_timeout = 1');
            DB::setDefaultConnection('mysql_advance_event_lock_test');

            try {
                app(OperationalAdvanceReconciliationService::class)->receiveReturn($advance, $return, $finance, $account->id);
                $this->fail('The second connection did not wait for the operational advance lock.');
            } catch (QueryException $exception) {
                $this->assertSame(1205, $exception->errorInfo[1]);
            }

            DB::setDefaultConnection('mysql');
            $primaryConnection->commit();
            DB::setDefaultConnection('mysql_advance_event_lock_test');

            $posted = app(OperationalAdvanceReconciliationService::class)->receiveReturn($advance, $return, $finance, $account->id);
            $retried = app(OperationalAdvanceReconciliationService::class)->receiveReturn($advance, $return, $finance, $account->id);
            $this->assertSame($posted->id, $retried->id);

            DB::setDefaultConnection('mysql');
            $this->assertSame(1, DB::table('cash_movements')->where('transaction_id', $return->id)->where('kind', 'advance_return')->count());
            $this->assertSame('800.00', CashAccount::query()->findOrFail($account->id)->ledgerBalance());
            $this->assertSame('settled', OperationalAdvance::query()->findOrFail($advance->id)->status);
        } finally {
            DB::setDefaultConnection('mysql');
            if ($primaryConnection->transactionLevel() > 0) {
                $primaryConnection->rollBack();
            }

            $transactionIds = DB::table('transactions')->where('operational_advance_id', $advance->id)->pluck('id');
            DB::table('audit_logs')->where('subject_type', $advance->getMorphClass())->where('subject_id', $advance->id)->delete();
            if ($transactionIds->isNotEmpty()) {
                DB::table('audit_logs')->where('subject_type', (new Transaction)->getMorphClass())->whereIn('subject_id', $transactionIds)->delete();
                DB::table('cash_movements')->whereIn('transaction_id', $transactionIds)->delete();
                DB::table('transactions')->whereIn('id', $transactionIds)->delete();
            }
            DB::table('cash_movements')->where('idempotency_key', $openingKey)->delete();
            DB::table('operational_advances')->where('id', $advance->id)->delete();
            DB::table('transaction_number_sequences')->where(['type' => 'advance_payout', 'period' => $transactionPeriod])->delete();
            DB::table('transaction_number_sequences')->where(['type' => 'advance_return', 'period' => $transactionPeriod])->delete();
            DB::table('advance_number_sequences')->where('period', $advancePeriod)->delete();
            DB::table('cash_accounts')->where('id', $account->id)->delete();
            DB::table('users')->whereIn('id', [$recipient->id, $finance->id])->delete();
            DB::purge('mysql_advance_event_lock_test');
        }
    }

    private function userWithRole(string $roleName): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('name', $roleName)->firstOrFail());

        return $user;
    }
}
