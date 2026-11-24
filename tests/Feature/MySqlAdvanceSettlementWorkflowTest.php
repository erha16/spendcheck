<?php

namespace Tests\Feature;

use App\Models\AdvanceSettlement;
use App\Models\AdvanceSettlementAttachment;
use App\Models\AdvanceSettlementItem;
use App\Models\Category;
use App\Models\OperationalAdvance;
use App\Models\Role;
use App\Models\User;
use App\Services\AdvanceSettlementWorkflow;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class MySqlAdvanceSettlementWorkflowTest extends TestCase
{
    public function test_mysql_advance_lock_serializes_batch_submission_and_idempotent_retry(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Tes row lock hanya dijalankan pada MySQL.');
        }

        $primaryConnection = DB::connection();
        if (! str_ends_with((string) $primaryConnection->getDatabaseName(), '_testing')) {
            $this->markTestSkipped('Tes locking hanya dijalankan pada database MySQL terpisah dengan suffix _testing.');
        }

        config(['database.connections.mysql_settlement_lock_test' => config('database.connections.mysql')]);
        DB::purge('mysql_settlement_lock_test');
        DB::setDefaultConnection('mysql');
        Storage::fake('local');
        $this->seed(AccessControlSeeder::class);

        $recipient = $this->userWithRole('driver');
        $advance = OperationalAdvance::factory()->create([
            'requester_user_id' => $recipient->id,
            'responsible_user_id' => $recipient->id,
            'created_by' => $recipient->id,
            'status' => 'disbursed',
            'approved_amount' => '1000.00',
            'disbursed_amount' => '1000.00',
            'disbursed_at' => now()->subDay(),
        ]);
        $category = Category::factory()->create();
        $submissionKey = (string) Str::uuid7();
        $data = [
            'submission_key' => $submissionKey,
            'items' => [[
                'occurred_at' => now()->subHour()->format('Y-m-d\\TH:i'),
                'description' => 'Pembelian operasional uji locking MySQL',
                'category_id' => $category->id,
                'amount' => '125.00',
                'attachments' => [UploadedFile::fake()->createWithContent('bukti.pdf', "%PDF-1.4\nBukti")],
            ]],
        ];
        $lockConnection = DB::connection('mysql_settlement_lock_test');
        $primaryConnection->beginTransaction();
        $primaryConnection->table('operational_advances')->where('id', $advance->id)->lockForUpdate()->first();
        $lockConnection->statement('SET SESSION innodb_lock_wait_timeout = 1');
        DB::setDefaultConnection('mysql_settlement_lock_test');

        try {
            try {
                app(AdvanceSettlementWorkflow::class)->submitBatch($advance, $recipient, $data);
                $this->fail('Batch submission did not wait for the bon row lock.');
            } catch (QueryException $exception) {
                $this->assertSame(1205, $exception->errorInfo[1]);
            }

            DB::setDefaultConnection('mysql');
            $primaryConnection->commit();
            $batch = app(AdvanceSettlementWorkflow::class)->submitBatch($advance->fresh(), $recipient, $data);
            $retry = app(AdvanceSettlementWorkflow::class)->submitBatch($advance->fresh(), $recipient, $data);

            $this->assertSame($batch->id, $retry->id);
            $this->assertSame($submissionKey, $retry->submission_key);
            $this->assertSame(1, AdvanceSettlement::query()->where('operational_advance_id', $advance->id)->count());
            $this->assertSame(1, DB::table('advance_settlement_items')->where('advance_settlement_id', $batch->id)->count());
            $this->assertSame(1, DB::table('advance_settlement_attachments')->count());
        } finally {
            DB::setDefaultConnection('mysql');
            if ($primaryConnection->transactionLevel() > 0) {
                $primaryConnection->rollBack();
            }

            $batchIds = DB::table('advance_settlements')->where('operational_advance_id', $advance->id)->pluck('id');
            $itemIds = DB::table('advance_settlement_items')->whereIn('advance_settlement_id', $batchIds)->pluck('id');
            $attachmentIds = DB::table('advance_settlement_attachments')->whereIn('advance_settlement_item_id', $itemIds)->pluck('id');
            foreach ([
                [new OperationalAdvance, $advance->id],
                [new AdvanceSettlement, $batchIds->all()],
                [new AdvanceSettlementItem, $itemIds->all()],
                [new AdvanceSettlementAttachment, $attachmentIds->all()],
            ] as [$subject, $subjectIds]) {
                DB::table('audit_logs')->where('subject_type', $subject->getMorphClass())
                    ->whereIn('subject_id', (array) $subjectIds)->delete();
            }
            DB::table('advance_settlement_attachments')->whereIn('advance_settlement_item_id', $itemIds)->delete();
            DB::table('advance_settlement_items')->whereIn('id', $itemIds)->delete();
            DB::table('advance_settlements')->whereIn('id', $batchIds)->delete();
            DB::table('operational_advances')->where('id', $advance->id)->delete();
            DB::table('advance_number_sequences')->where('period', $advance->created_at->format('Ym'))->delete();
            DB::table('categories')->where('id', $category->id)->delete();
            DB::table('cash_accounts')->where('id', $advance->planned_cash_account_id)->delete();
            DB::table('users')->where('id', $recipient->id)->delete();
            DB::purge('mysql_settlement_lock_test');
        }
    }

    private function userWithRole(string $roleName): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('name', $roleName)->firstOrFail());

        return $user;
    }
}
