<?php

namespace Tests\Feature;

use App\Models\CashAccount;
use App\Models\CashMovement;
use App\Models\Category;
use App\Models\FundSource;
use App\Models\OpeningBalanceRequest;
use App\Models\PaymentMethod;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\User;
use App\Services\OpeningBalanceService;
use App\Services\TransactionNumberGenerator;
use App\Services\TransactionWorkflow;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MySqlTransactionNumberLockTest extends TestCase
{
    public function test_transaction_number_generator_waits_for_mysql_row_lock_and_uses_next_value(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Tes row lock harus dijalankan pada MySQL.');
        }

        config(['database.connections.mysql_lock_test' => config('database.connections.mysql')]);
        DB::purge('mysql_lock_test');

        $primaryConnection = DB::connection('mysql');
        $lockTestConnection = DB::connection('mysql_lock_test');
        $sequenceId = DB::table('transaction_number_sequences')->insertGetId([
            'type' => 'expense',
            'period' => '209912',
            'last_number' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $primaryConnection->beginTransaction();
        $primaryConnection->table('transaction_number_sequences')->where('id', $sequenceId)->lockForUpdate()->first();
        $lockTestConnection->statement('SET SESSION innodb_lock_wait_timeout = 1');
        DB::setDefaultConnection('mysql_lock_test');

        try {
            try {
                app(TransactionNumberGenerator::class)->next('expense', '2099-12-01');
                $this->fail('Generator did not wait for the sequence row lock.');
            } catch (QueryException $exception) {
                $this->assertSame(1205, $exception->errorInfo[1]);
            }
        } finally {
            DB::setDefaultConnection('mysql');
            $primaryConnection->rollBack();
        }

        try {
            $this->assertSame('EXP/209912/0001', app(TransactionNumberGenerator::class)->next('expense', '2099-12-01'));
        } finally {
            DB::table('transaction_number_sequences')->where('id', $sequenceId)->delete();
            DB::purge('mysql_lock_test');
        }
    }

    public function test_posting_waits_for_mysql_cash_account_lock(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Tes row lock harus dijalankan pada MySQL.');
        }

        config(['database.connections.mysql_lock_test' => config('database.connections.mysql')]);
        DB::purge('mysql_lock_test');
        DB::setDefaultConnection('mysql');

        $user = User::factory()->create();
        $account = CashAccount::factory()->create();
        $paymentMethod = PaymentMethod::factory()->create();
        $occurredAt = now()->subDay();
        $transaction = Transaction::factory()->create([
            'type' => 'income',
            'occurred_at' => $occurredAt,
            'description' => 'MySQL posting lock test.',
            'responsible_user_id' => $user->id,
            'created_by' => $user->id,
            'payment_method_id' => $paymentMethod->id,
            'source_cash_account_id' => null,
            'destination_cash_account_id' => $account->id,
            'fund_source_id' => null,
            'total_amount' => '10.00',
            'status' => 'submitted',
        ]);

        $primaryConnection = DB::connection('mysql');
        $lockTestConnection = DB::connection('mysql_lock_test');
        $primaryConnection->beginTransaction();
        $primaryConnection->table('cash_accounts')->where('id', $account->id)->lockForUpdate()->first();
        $lockTestConnection->statement('SET SESSION innodb_lock_wait_timeout = 1');
        DB::setDefaultConnection('mysql_lock_test');

        try {
            try {
                app(TransactionWorkflow::class)->post($transaction, $user);
                $this->fail('Posting did not wait for the cash account row lock.');
            } catch (QueryException $exception) {
                $this->assertSame(1205, $exception->errorInfo[1]);
            }
        } finally {
            DB::setDefaultConnection('mysql');
            $primaryConnection->rollBack();
            Transaction::query()->whereKey($transaction->id)->delete();
            DB::table('transaction_number_sequences')->where(['type' => 'income', 'period' => $occurredAt->format('Ym')])->delete();
            $account->delete();
            $paymentMethod->delete();
            $user->delete();
            DB::purge('mysql_lock_test');
        }
    }

    public function test_correction_waits_for_mysql_original_transaction_lock(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Tes row lock harus dijalankan pada MySQL.');
        }

        config(['database.connections.mysql_lock_test' => config('database.connections.mysql')]);
        DB::purge('mysql_lock_test');
        DB::setDefaultConnection('mysql');
        $this->seed(AccessControlSeeder::class);
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('name', 'keuangan_sarpras')->firstOrFail());
        $account = CashAccount::factory()->create();
        $method = PaymentMethod::factory()->create();
        $transaction = Transaction::factory()->create([
            'type' => 'income', 'occurred_at' => '2026-05-20 10:00:00', 'description' => 'MySQL correction lock test.',
            'responsible_user_id' => $user->id, 'created_by' => $user->id, 'payment_method_id' => $method->id,
            'source_cash_account_id' => null, 'destination_cash_account_id' => $account->id, 'total_amount' => '10.00',
            'status' => 'posted', 'posted_by' => $user->id, 'posted_at' => now(),
        ]);
        $movement = CashMovement::create([
            'cash_account_id' => $account->id, 'transaction_id' => $transaction->id, 'kind' => 'income', 'direction' => 'in',
            'amount' => '10.00', 'idempotency_key' => 'mysql-correction-original-'.$transaction->public_id,
            'occurred_at' => $transaction->occurred_at, 'recorded_at' => now(), 'created_by' => $user->id,
        ]);
        $correctionData = [
            'type' => 'income', 'occurred_at' => '2026-05-21 12:00:00', 'description' => 'Corrected MySQL income.',
            'responsible_user_id' => $user->id, 'payment_method_id' => $method->id, 'destination_cash_account_id' => $account->id,
            'income_mode' => 'direct', 'income_amount' => '12.00',
        ];

        $primaryConnection = DB::connection('mysql');
        $lockTestConnection = DB::connection('mysql_lock_test');
        $primaryConnection->beginTransaction();
        $primaryConnection->table('transactions')->where('id', $transaction->id)->lockForUpdate()->first();

        try {
            app(TransactionWorkflow::class)->correct($transaction, $user, $correctionData, 'Correct original income.');
            $lockTestConnection->statement('SET SESSION innodb_lock_wait_timeout = 1');
            DB::setDefaultConnection('mysql_lock_test');
            try {
                app(TransactionWorkflow::class)->correct($transaction, $user, $correctionData, 'Duplicate concurrent correction.');
                $this->fail('The second correction did not wait for the original transaction row lock.');
            } catch (QueryException $exception) {
                $this->assertSame(1205, $exception->errorInfo[1]);
            }

            DB::setDefaultConnection('mysql');
            $primaryConnection->commit();
            DB::setDefaultConnection('mysql_lock_test');
            try {
                app(TransactionWorkflow::class)->correct($transaction, $user, $correctionData, 'Duplicate retry correction.');
                $this->fail('An already reversed transaction was corrected again.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('transaction', $exception->errors());
            }

            $replacement = Transaction::query()->where('reversal_of_id', $transaction->id)->firstOrFail();
            $this->assertSame(1, DB::table('transaction_revisions')->where('transaction_id', $transaction->id)->count());
            $this->assertSame(1, CashMovement::query()->where('reversal_of_id', $movement->id)->count());
            $this->assertSame(3, CashMovement::query()->whereIn('transaction_id', [$transaction->id, $replacement->id])->count());
        } finally {
            DB::setDefaultConnection('mysql');
            if ($primaryConnection->transactionLevel() > 0) {
                $primaryConnection->rollBack();
            }
            $replacement = Transaction::query()->where('reversal_of_id', $transaction->id)->first();
            DB::table('audit_logs')->whereIn('subject_id', array_filter([$transaction->id, $replacement?->id]))->delete();
            DB::table('transaction_revisions')->where('transaction_id', $transaction->id)->delete();
            DB::table('cash_movements')->whereIn('transaction_id', array_filter([$transaction->id, $replacement?->id]))->whereNotNull('reversal_of_id')->delete();
            DB::table('cash_movements')->whereIn('transaction_id', array_filter([$transaction->id, $replacement?->id]))->delete();
            $replacement?->delete();
            $transaction->delete();
            DB::table('transaction_number_sequences')->where(['type' => 'income', 'period' => '202605'])->delete();
            $account->delete();
            $method->delete();
            $user->delete();
            DB::purge('mysql_lock_test');
        }
    }

    public function test_transfer_posting_waits_for_the_lowest_id_account_lock_first(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Tes row lock harus dijalankan pada MySQL.');
        }

        config(['database.connections.mysql_lock_test' => config('database.connections.mysql')]);
        DB::purge('mysql_lock_test');
        DB::setDefaultConnection('mysql');
        $this->seed(AccessControlSeeder::class);
        Storage::fake('local');
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('name', 'keuangan_sarpras')->firstOrFail());
        $destination = CashAccount::factory()->create();
        $source = CashAccount::factory()->create();
        $openingMovementKey = 'mysql-transfer-lock-opening-'.$source->id;
        CashMovement::create([
            'cash_account_id' => $source->id,
            'kind' => 'opening_balance',
            'direction' => 'in',
            'amount' => '500.00',
            'idempotency_key' => $openingMovementKey,
            'occurred_at' => '2026-05-01 09:00:00',
            'recorded_at' => now(),
            'created_by' => $user->id,
        ]);
        $transaction = Transaction::factory()->create([
            'type' => 'transfer',
            'occurred_at' => '2026-05-02 10:00:00',
            'description' => 'MySQL transfer lock test.',
            'notes' => null,
            'responsible_user_id' => null,
            'payment_method_id' => null,
            'source_cash_account_id' => $source->id,
            'destination_cash_account_id' => $destination->id,
            'fund_source_id' => null,
            'vendor_id' => null,
            'total_amount' => '100.00',
            'status' => 'submitted',
            'created_by' => $user->id,
        ]);
        $proofPath = 'transactions/'.$transaction->public_id.'/proof.pdf';
        $proofContents = "%PDF-1.4\nProof";
        Storage::disk('local')->put($proofPath, $proofContents);
        $transaction->attachments()->create([
            'original_name' => 'proof.pdf',
            'disk' => 'local',
            'path' => $proofPath,
            'mime_type' => 'application/pdf',
            'size_bytes' => strlen($proofContents),
            'checksum' => hash('sha256', $proofContents),
            'uploaded_by' => $user->id,
        ]);

        $primaryConnection = DB::connection('mysql');
        $lockTestConnection = DB::connection('mysql_lock_test');
        $primaryConnection->beginTransaction();
        $primaryConnection->table('cash_accounts')->where('id', $destination->id)->lockForUpdate()->first();
        $lockTestConnection->statement('SET SESSION innodb_lock_wait_timeout = 1');
        DB::setDefaultConnection('mysql_lock_test');

        try {
            try {
                app(TransactionWorkflow::class)->post($transaction, $user);
                $this->fail('Transfer posting did not wait for the lowest-ID cash account lock.');
            } catch (QueryException $exception) {
                $this->assertSame(1205, $exception->errorInfo[1]);
            }
        } finally {
            DB::setDefaultConnection('mysql');
            $primaryConnection->rollBack();
            DB::table('transaction_attachments')->where('transaction_id', $transaction->id)->delete();
            Transaction::query()->whereKey($transaction->id)->delete();
            DB::table('transaction_number_sequences')->where(['type' => 'transfer', 'period' => '202605'])->delete();
            DB::table('cash_movements')->where('idempotency_key', $openingMovementKey)->delete();
            $source->delete();
            $destination->delete();
            $user->delete();
            DB::purge('mysql_lock_test');
        }
    }

    public function test_two_concurrent_expenses_cannot_overdraw_the_same_cash_account(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Tes row lock harus dijalankan pada MySQL.');
        }

        config(['database.connections.mysql_lock_test' => config('database.connections.mysql')]);
        DB::purge('mysql_lock_test');
        DB::setDefaultConnection('mysql');
        $this->seed(AccessControlSeeder::class);
        Storage::fake('local');
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('name', 'keuangan_sarpras')->firstOrFail());
        $account = CashAccount::factory()->create();
        $category = Category::factory()->create();
        $fund = FundSource::factory()->create();
        $method = PaymentMethod::factory()->create();
        $openingKey = 'mysql-expense-race-opening-'.$account->id;
        $this->createOpeningMovement($account, $user, '100.00', $openingKey);
        $first = $this->createSubmittedExpense($user, $account, $category, $fund, $method, 'first');
        $second = $this->createSubmittedExpense($user, $account, $category, $fund, $method, 'second');
        $primaryConnection = DB::connection('mysql');
        $lockTestConnection = DB::connection('mysql_lock_test');
        $primaryConnection->beginTransaction();
        $primaryConnection->table('cash_accounts')->where('id', $account->id)->lockForUpdate()->first();

        try {
            app(TransactionWorkflow::class)->post($first, $user);
            $lockTestConnection->statement('SET SESSION innodb_lock_wait_timeout = 1');
            DB::setDefaultConnection('mysql_lock_test');
            try {
                app(TransactionWorkflow::class)->post($second, $user);
                $this->fail('The second expense did not wait on the locked cash account.');
            } catch (QueryException $exception) {
                $this->assertSame(1205, $exception->errorInfo[1]);
            }

            DB::setDefaultConnection('mysql');
            $primaryConnection->commit();
            DB::setDefaultConnection('mysql_lock_test');
            try {
                app(TransactionWorkflow::class)->post($second, $user);
                $this->fail('The second expense posted using a stale balance.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('allow_negative', $exception->errors());
            }

            $this->assertSame('20.00', CashAccount::query()->findOrFail($account->id)->ledgerBalance());
            $this->assertSame(1, CashMovement::query()->where('transaction_id', $first->id)->count());
            $this->assertSame(0, CashMovement::query()->where('transaction_id', $second->id)->count());
        } finally {
            DB::setDefaultConnection('mysql');
            if ($primaryConnection->transactionLevel() > 0) {
                $primaryConnection->rollBack();
            }
            $this->cleanupTransactions([$first, $second], [$account], [$category, $fund, $method], $user, [$openingKey]);
            DB::purge('mysql_lock_test');
        }
    }

    public function test_two_concurrent_transfers_cannot_spend_the_same_source_balance_twice(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Tes row lock harus dijalankan pada MySQL.');
        }

        config(['database.connections.mysql_lock_test' => config('database.connections.mysql')]);
        DB::purge('mysql_lock_test');
        DB::setDefaultConnection('mysql');
        $this->seed(AccessControlSeeder::class);
        Storage::fake('local');
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('name', 'keuangan_sarpras')->firstOrFail());
        $source = CashAccount::factory()->create();
        $firstDestination = CashAccount::factory()->create();
        $secondDestination = CashAccount::factory()->create();
        $openingKey = 'mysql-transfer-race-opening-'.$source->id;
        $this->createOpeningMovement($source, $user, '100.00', $openingKey);
        $first = $this->createSubmittedTransfer($user, $source, $firstDestination, 'first');
        $second = $this->createSubmittedTransfer($user, $source, $secondDestination, 'second');
        $primaryConnection = DB::connection('mysql');
        $lockTestConnection = DB::connection('mysql_lock_test');
        $primaryConnection->beginTransaction();
        $primaryConnection->table('cash_accounts')->where('id', $source->id)->lockForUpdate()->first();

        try {
            app(TransactionWorkflow::class)->post($first, $user);
            $lockTestConnection->statement('SET SESSION innodb_lock_wait_timeout = 1');
            DB::setDefaultConnection('mysql_lock_test');
            try {
                app(TransactionWorkflow::class)->post($second, $user);
                $this->fail('The second transfer did not wait on the locked source account.');
            } catch (QueryException $exception) {
                $this->assertSame(1205, $exception->errorInfo[1]);
            }

            DB::setDefaultConnection('mysql');
            $primaryConnection->commit();
            DB::setDefaultConnection('mysql_lock_test');
            try {
                app(TransactionWorkflow::class)->post($second, $user);
                $this->fail('The second transfer posted using a stale source balance.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('source_cash_account_id', $exception->errors());
            }

            $this->assertSame('20.00', CashAccount::query()->findOrFail($source->id)->ledgerBalance());
            $this->assertSame(2, CashMovement::query()->where('transaction_id', $first->id)->count());
            $this->assertSame(0, CashMovement::query()->where('transaction_id', $second->id)->count());
        } finally {
            DB::setDefaultConnection('mysql');
            if ($primaryConnection->transactionLevel() > 0) {
                $primaryConnection->rollBack();
            }
            $this->cleanupTransactions([$first, $second], [$source, $firstDestination, $secondDestination], [], $user, [$openingKey]);
            DB::purge('mysql_lock_test');
        }
    }

    public function test_concurrent_post_requests_for_one_transaction_create_one_movement(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Tes row lock harus dijalankan pada MySQL.');
        }

        config(['database.connections.mysql_lock_test' => config('database.connections.mysql')]);
        DB::purge('mysql_lock_test');
        DB::setDefaultConnection('mysql');
        $this->seed(AccessControlSeeder::class);
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('name', 'keuangan_sarpras')->firstOrFail());
        $account = CashAccount::factory()->create();
        $method = PaymentMethod::factory()->create();
        $transaction = Transaction::factory()->create([
            'type' => 'income', 'occurred_at' => '2026-11-21 10:00:00', 'description' => 'Concurrent duplicate posting.',
            'responsible_user_id' => $user->id, 'created_by' => $user->id, 'payment_method_id' => $method->id,
            'source_cash_account_id' => null, 'destination_cash_account_id' => $account->id, 'total_amount' => '10.00',
            'status' => 'submitted',
        ]);
        $primaryConnection = DB::connection('mysql');
        $lockTestConnection = DB::connection('mysql_lock_test');
        $primaryConnection->beginTransaction();
        $primaryConnection->table('transactions')->where('id', $transaction->id)->lockForUpdate()->first();

        try {
            app(TransactionWorkflow::class)->post($transaction, $user);
            $lockTestConnection->statement('SET SESSION innodb_lock_wait_timeout = 1');
            DB::setDefaultConnection('mysql_lock_test');
            try {
                app(TransactionWorkflow::class)->post($transaction, $user);
                $this->fail('The duplicate post did not wait on the transaction lock.');
            } catch (QueryException $exception) {
                $this->assertSame(1205, $exception->errorInfo[1]);
            }

            DB::setDefaultConnection('mysql');
            $primaryConnection->commit();
            DB::setDefaultConnection('mysql_lock_test');
            try {
                app(TransactionWorkflow::class)->post($transaction, $user);
                $this->fail('The already posted transaction was posted again.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('transaction', $exception->errors());
            }

            $this->assertSame(1, CashMovement::query()->where('transaction_id', $transaction->id)->count());
        } finally {
            DB::setDefaultConnection('mysql');
            if ($primaryConnection->transactionLevel() > 0) {
                $primaryConnection->rollBack();
            }
            $this->cleanupTransactions([$transaction], [$account], [$method], $user);
            DB::purge('mysql_lock_test');
        }
    }

    public function test_concurrent_approval_of_opening_balance_creates_one_movement(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Tes row lock harus dijalankan pada MySQL.');
        }

        config(['database.connections.mysql_lock_test' => config('database.connections.mysql')]);
        DB::purge('mysql_lock_test');
        DB::setDefaultConnection('mysql');
        $this->seed(AccessControlSeeder::class);
        $proposer = User::factory()->create();
        $proposer->roles()->attach(Role::query()->where('name', 'keuangan_sarpras')->firstOrFail());
        $reviewer = User::factory()->create();
        $reviewer->roles()->attach(Role::query()->where('name', 'kepala_sarpras')->firstOrFail());
        $account = CashAccount::factory()->create();
        $request = OpeningBalanceRequest::factory()->create([
            'cash_account_id' => $account->id, 'amount' => '75.00', 'proposed_by' => $proposer->id,
        ]);
        $primaryConnection = DB::connection('mysql');
        $lockTestConnection = DB::connection('mysql_lock_test');
        $primaryConnection->beginTransaction();
        $primaryConnection->table('opening_balance_requests')->where('id', $request->id)->lockForUpdate()->first();

        try {
            app(OpeningBalanceService::class)->review($request, $reviewer, 'approved', 'Diverifikasi.');
            $lockTestConnection->statement('SET SESSION innodb_lock_wait_timeout = 1');
            DB::setDefaultConnection('mysql_lock_test');
            try {
                app(OpeningBalanceService::class)->review($request, $reviewer, 'approved', 'Diverifikasi ulang.');
                $this->fail('The second opening balance approval did not wait for the request lock.');
            } catch (QueryException $exception) {
                $this->assertSame(1205, $exception->errorInfo[1]);
            }

            DB::setDefaultConnection('mysql');
            $primaryConnection->commit();
            DB::setDefaultConnection('mysql_lock_test');
            app(OpeningBalanceService::class)->review($request, $reviewer, 'approved', 'Retry approval.');

            $this->assertSame('approved', OpeningBalanceRequest::query()->findOrFail($request->id)->status);
            $this->assertSame(1, CashMovement::query()->where('opening_balance_request_id', $request->id)->count());
            $this->assertSame('75.00', CashAccount::query()->findOrFail($account->id)->ledgerBalance());
        } finally {
            DB::setDefaultConnection('mysql');
            if ($primaryConnection->transactionLevel() > 0) {
                $primaryConnection->rollBack();
            }
            DB::table('audit_logs')->where('subject_type', $request->getMorphClass())->where('subject_id', $request->id)->delete();
            DB::table('cash_movements')->where('opening_balance_request_id', $request->id)->delete();
            $request->delete();
            $account->delete();
            $reviewer->delete();
            $proposer->delete();
            DB::purge('mysql_lock_test');
        }
    }

    private function createOpeningMovement(CashAccount $account, User $user, string $amount, string $key): void
    {
        CashMovement::create([
            'cash_account_id' => $account->id, 'kind' => 'opening_balance', 'direction' => 'in', 'amount' => $amount,
            'idempotency_key' => $key, 'occurred_at' => '2026-11-01 09:00:00', 'recorded_at' => now(), 'created_by' => $user->id,
        ]);
    }

    private function createSubmittedExpense(User $user, CashAccount $account, Category $category, FundSource $fund, PaymentMethod $method, string $suffix): Transaction
    {
        $transaction = Transaction::factory()->create([
            'type' => 'expense', 'occurred_at' => '2026-11-20 10:00:00', 'description' => 'Concurrent expense '.$suffix,
            'responsible_user_id' => $user->id, 'created_by' => $user->id, 'payment_method_id' => $method->id,
            'source_cash_account_id' => $account->id, 'destination_cash_account_id' => null, 'fund_source_id' => $fund->id,
            'total_amount' => '80.00', 'status' => 'submitted',
        ]);
        TransactionItem::create([
            'transaction_id' => $transaction->id, 'description' => 'Expense item '.$suffix, 'category_id' => $category->id,
            'quantity' => '1.0000', 'unit' => 'unit', 'unit_price' => '80.00', 'subtotal' => '80.00',
        ]);
        $this->attachProof($transaction, $user, $suffix);

        return $transaction;
    }

    private function createSubmittedTransfer(User $user, CashAccount $source, CashAccount $destination, string $suffix): Transaction
    {
        $transaction = Transaction::factory()->create([
            'type' => 'transfer', 'occurred_at' => '2026-11-20 11:00:00', 'description' => 'Concurrent transfer '.$suffix,
            'notes' => null, 'responsible_user_id' => null, 'created_by' => $user->id, 'payment_method_id' => null,
            'source_cash_account_id' => $source->id, 'destination_cash_account_id' => $destination->id,
            'fund_source_id' => null, 'vendor_id' => null, 'total_amount' => '80.00', 'status' => 'submitted',
        ]);
        $this->attachProof($transaction, $user, $suffix);

        return $transaction;
    }

    private function attachProof(Transaction $transaction, User $user, string $suffix): void
    {
        $path = 'transactions/'.$transaction->public_id.'/'.$suffix.'.pdf';
        $contents = "%PDF-1.4\nProof";
        Storage::disk('local')->put($path, $contents);
        $transaction->attachments()->create([
            'original_name' => $suffix.'.pdf', 'disk' => 'local', 'path' => $path, 'mime_type' => 'application/pdf',
            'size_bytes' => strlen($contents), 'checksum' => hash('sha256', $contents), 'uploaded_by' => $user->id,
        ]);
    }

    /** @param list<Transaction> $transactions
     * @param  list<CashAccount>  $accounts
     * @param  list<Model>  $masters
     * @param  list<string>  $openingKeys
     */
    private function cleanupTransactions(array $transactions, array $accounts, array $masters, User $user, array $openingKeys = []): void
    {
        $transactionIds = collect($transactions)->pluck('id')->all();
        DB::table('audit_logs')->where('subject_type', (new Transaction)->getMorphClass())->whereIn('subject_id', $transactionIds)->delete();
        DB::table('transaction_attachments')->whereIn('transaction_id', $transactionIds)->delete();
        DB::table('transaction_items')->whereIn('transaction_id', $transactionIds)->delete();
        DB::table('cash_movements')->whereIn('transaction_id', $transactionIds)->delete();
        if ($openingKeys !== []) {
            DB::table('cash_movements')->whereIn('idempotency_key', $openingKeys)->delete();
        }
        Transaction::query()->whereIn('id', $transactionIds)->delete();
        DB::table('transaction_number_sequences')->where('period', '202611')->delete();
        foreach ($accounts as $account) {
            $account->delete();
        }
        foreach ($masters as $master) {
            $master->delete();
        }
        $user->delete();
    }
}
