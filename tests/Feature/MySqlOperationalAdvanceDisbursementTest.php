<?php

namespace Tests\Feature;

use App\Models\CashAccount;
use App\Models\CashMovement;
use App\Models\OperationalAdvance;
use App\Models\Role;
use App\Models\User;
use App\Services\OperationalAdvanceDisbursementService;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MySqlOperationalAdvanceDisbursementTest extends TestCase
{
    public function test_two_mysql_connections_cannot_disburse_the_same_advance_twice(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Tes row lock harus dijalankan pada MySQL.');
        }

        $primaryConnection = DB::connection('mysql');
        if (! str_ends_with($primaryConnection->getDatabaseName(), '_testing')) {
            $this->markTestSkipped('Tes locking hanya dijalankan pada database MySQL terpisah dengan suffix _testing.');
        }

        config(['database.connections.mysql_lock_test' => config('database.connections.mysql')]);
        DB::purge('mysql_lock_test');
        DB::setDefaultConnection('mysql');
        $this->seed(AccessControlSeeder::class);

        $requester = $this->userWithRole('driver');
        $finance = $this->userWithRole('keuangan_sarpras');
        $advance = OperationalAdvance::factory()->create([
            'requester_user_id' => $requester->id,
            'responsible_user_id' => $requester->id,
            'created_by' => $requester->id,
            'status' => 'approved',
            'approved_amount' => '500.00',
            'approved_by' => $finance->id,
            'approved_at' => now()->subMinute(),
        ]);
        $account = CashAccount::factory()->create();
        $openingKey = 'mysql-advance-payout-opening-'.$advance->public_id;
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

        $lockTestConnection = DB::connection('mysql_lock_test');
        $primaryConnection->beginTransaction();
        $primaryConnection->table('operational_advances')->where('id', $advance->id)->lockForUpdate()->first();
        $occurredAt = now()->subMinute();
        $transactionPeriod = $occurredAt->format('Ym');
        $advancePeriod = $advance->created_at->format('Ym');

        try {
            app(OperationalAdvanceDisbursementService::class)->disburse($advance, $finance, $account->id, $occurredAt);
            $lockTestConnection->statement('SET SESSION innodb_lock_wait_timeout = 1');
            DB::setDefaultConnection('mysql_lock_test');

            try {
                app(OperationalAdvanceDisbursementService::class)->disburse($advance, $finance, $account->id, $occurredAt);
                $this->fail('The second payout did not wait for the operational advance lock.');
            } catch (QueryException $exception) {
                $this->assertSame(1205, $exception->errorInfo[1]);
            }

            DB::setDefaultConnection('mysql');
            $primaryConnection->commit();
            DB::setDefaultConnection('mysql_lock_test');

            try {
                app(OperationalAdvanceDisbursementService::class)->disburse($advance, $finance, $account->id, $occurredAt);
                $this->fail('The retry should reject an advance already disbursed by the first connection.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('operational_advance', $exception->errors());
            }

            DB::setDefaultConnection('mysql');
            $this->assertSame('disbursed', OperationalAdvance::query()->findOrFail($advance->id)->status);
            $this->assertSame(1, DB::table('transactions')->where('operational_advance_id', $advance->id)->where('type', 'advance_payout')->count());
            $this->assertSame(1, DB::table('cash_movements')->where('idempotency_key', 'advance-payout:'.$advance->public_id)->count());
            $this->assertSame('300.00', CashAccount::query()->findOrFail($account->id)->ledgerBalance());
        } finally {
            DB::setDefaultConnection('mysql');
            if ($primaryConnection->transactionLevel() > 0) {
                $primaryConnection->rollBack();
            }

            $transactionIds = DB::table('transactions')->where('operational_advance_id', $advance->id)->pluck('id');
            DB::table('audit_logs')->where('subject_type', $advance->getMorphClass())->where('subject_id', $advance->id)->delete();
            if ($transactionIds->isNotEmpty()) {
                DB::table('cash_movements')->whereIn('transaction_id', $transactionIds)->delete();
                DB::table('transactions')->whereIn('id', $transactionIds)->delete();
            }
            DB::table('cash_movements')->where('idempotency_key', $openingKey)->delete();
            DB::table('operational_advances')->where('id', $advance->id)->delete();
            DB::table('transaction_number_sequences')->where(['type' => 'advance_payout', 'period' => $transactionPeriod])->delete();
            DB::table('advance_number_sequences')->where('period', $advancePeriod)->delete();
            DB::table('cash_accounts')->where('id', $account->id)->delete();
            DB::table('users')->whereIn('id', [$requester->id, $finance->id])->delete();
            DB::purge('mysql_lock_test');
        }
    }

    private function userWithRole(string $roleName): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('name', $roleName)->firstOrFail());

        return $user;
    }
}
