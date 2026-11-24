<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\CashAccount;
use App\Models\Category;
use App\Models\FundSource;
use App\Models\PaymentMethod;
use App\Models\Role;
use App\Models\Subcategory;
use App\Models\Transaction;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TransactionWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
    }

    public function test_petugas_can_create_an_expense_draft_with_backend_calculated_totals(): void
    {
        Storage::fake('local');
        [$petugas, $masters] = $this->expenseSetup('petugas_sarpras');
        $payload = $this->expensePayload($masters);
        $payload['attachments'] = [UploadedFile::fake()->createWithContent('nota.pdf', "%PDF-1.4\nNota")];
        $payload['items'][0]['subtotal'] = '0.01';
        $payload['total_amount'] = '0.01';
        $payload['status'] = 'posted';
        $payload['posted_by'] = $petugas->id;
        $payload['posted_at'] = now()->toDateTimeString();
        $payload['negative_balance_override'] = true;

        $response = $this->actingAs($petugas)->post(route('transactions.store'), $payload);
        $transaction = Transaction::query()->firstOrFail();

        $response->assertRedirect(route('transactions.show', $transaction));
        $this->assertSame('draft', $transaction->status);
        $this->assertSame('250.00', $transaction->total_amount);
        $this->assertSame('250.00', $transaction->items()->firstOrFail()->subtotal);
        $this->assertSame($petugas->id, $transaction->created_by);
        $this->assertNull($transaction->posted_by);
        $this->assertNull($transaction->posted_at);
        $this->assertFalse($transaction->negative_balance_override);
        $this->assertDatabaseCount('cash_movements', 0);
        $this->assertDatabaseHas('transaction_attachments', ['transaction_id' => $transaction->id, 'disk' => 'local', 'original_name' => 'nota.pdf']);
        $this->assertCount(1, Storage::disk('local')->allFiles('transactions/'.$transaction->public_id));
        $this->assertDatabaseHas('audit_logs', ['action' => 'transaction_created']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'transaction_attachment_uploaded']);
    }

    public function test_petugas_cannot_read_another_users_transaction_by_public_id(): void
    {
        $petugas = $this->userWithRole('petugas_sarpras');
        $otherUser = User::factory()->create();
        $transaction = Transaction::factory()->create(['created_by' => $otherUser->id, 'type' => 'expense']);

        $this->actingAs($petugas)->get(route('transactions.show', $transaction))->assertNotFound();
        $this->get(route('transactions.edit', $transaction))->assertNotFound();
    }

    public function test_finance_can_create_and_post_direct_income_once_to_the_ledger(): void
    {
        [$financeUser, $masters] = $this->expenseSetup('keuangan_sarpras');
        $payload = [
            'type' => 'income',
            'income_mode' => 'direct',
            'income_amount' => '1250.75',
            'occurred_at' => '2026-05-14 11:30:00',
            'description' => 'Penerimaan dana kegiatan.',
            'responsible_user_id' => $financeUser->id,
            'destination_cash_account_id' => $masters['cash_account']->id,
            'payment_method_id' => $masters['payment_method']->id,
        ];

        $this->actingAs($financeUser)->post(route('transactions.store'), $payload)->assertRedirect();
        $transaction = Transaction::query()->firstOrFail();
        $this->post(route('transactions.submit', $transaction))->assertRedirect();
        $this->post(route('transactions.post', $transaction))->assertRedirect();
        $this->post(route('transactions.post', $transaction))->assertSessionHasErrors('transaction');

        $this->assertSame('INC/202605/0001', $transaction->fresh()->number);
        $this->assertSame('posted', $transaction->fresh()->status);
        $this->assertSame($financeUser->id, $transaction->fresh()->posted_by);
        $this->assertSame('1250.75', $masters['cash_account']->ledgerBalance());
        $this->assertDatabaseCount('cash_movements', 1);
        $this->assertDatabaseHas('audit_logs', ['action' => 'transaction_posted']);
    }

    public function test_income_item_mode_calculates_total_without_client_categories(): void
    {
        [$financeUser, $masters] = $this->expenseSetup('keuangan_sarpras');
        $this->actingAs($financeUser)->post(route('transactions.store'), [
            'type' => 'income',
            'income_mode' => 'items',
            'occurred_at' => '2026-05-15',
            'description' => 'Rincian penerimaan.',
            'responsible_user_id' => $financeUser->id,
            'destination_cash_account_id' => $masters['cash_account']->id,
            'payment_method_id' => $masters['payment_method']->id,
            'items' => [
                ['description' => 'Penerimaan A', 'quantity' => '1', 'unit_price' => '10.25'],
                ['description' => 'Penerimaan B', 'quantity' => '1', 'unit_price' => '20.75'],
            ],
        ])->assertRedirect();

        $transaction = Transaction::query()->firstOrFail();
        $this->assertSame('31.00', $transaction->total_amount);
        $this->assertSame(2, $transaction->items()->count());
        $this->assertNull($transaction->items()->firstOrFail()->category_id);
        $this->assertSame(['10.25', '20.75'], $transaction->items()->orderBy('id')->pluck('subtotal')->all());
    }

    public function test_finance_can_request_revision_and_creator_can_resubmit(): void
    {
        [$petugas, $masters] = $this->expenseSetup('petugas_sarpras');
        $payload = $this->expensePayload($masters);
        $this->actingAs($petugas)->post(route('transactions.store'), $payload)->assertRedirect();
        $transaction = Transaction::query()->firstOrFail();
        $this->post(route('transactions.submit', $transaction))->assertRedirect();
        $financeUser = $this->userWithRole('keuangan_sarpras');

        $this->actingAs($financeUser)->post(route('transactions.review', $transaction), [
            'decision' => 'revision',
            'reason' => 'Mohon lengkapi rincian kebutuhan.',
        ])->assertRedirect();
        $this->assertSame('revision_requested', $transaction->fresh()->status);
        $this->assertSame('Mohon lengkapi rincian kebutuhan.', $transaction->fresh()->revision_reason);

        $revisedPayload = $payload;
        $revisedPayload['description'] = 'Transaksi diperbaiki.';
        $this->actingAs($petugas)->put(route('transactions.update', $transaction), $revisedPayload)->assertRedirect();
        $this->post(route('transactions.submit', $transaction))->assertRedirect();

        $this->assertSame('submitted', $transaction->fresh()->status);
        $this->assertNull($transaction->fresh()->revision_reason);
        $this->assertDatabaseHas('audit_logs', ['action' => 'transaction_revision_requested']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'transaction_submitted']);
    }

    public function test_finance_can_reject_a_submitted_transaction_with_reason(): void
    {
        [$petugas, $masters] = $this->expenseSetup('petugas_sarpras');
        $this->actingAs($petugas)->post(route('transactions.store'), $this->expensePayload($masters))->assertRedirect();
        $transaction = Transaction::query()->firstOrFail();
        $this->post(route('transactions.submit', $transaction))->assertRedirect();
        $financeUser = $this->userWithRole('keuangan_sarpras');

        $this->actingAs($financeUser)->post(route('transactions.review', $transaction), [
            'decision' => 'reject',
            'reason' => 'Dokumen tidak memenuhi persyaratan.',
        ])->assertRedirect();

        $this->assertSame('rejected', $transaction->fresh()->status);
        $this->assertSame('Dokumen tidak memenuhi persyaratan.', $transaction->fresh()->rejection_reason);
        $this->assertDatabaseCount('cash_movements', 0);
        $this->assertDatabaseHas('audit_logs', ['action' => 'transaction_rejected']);
    }

    public function test_petugas_cannot_post_and_posted_transaction_cannot_be_updated(): void
    {
        [$petugas, $masters] = $this->expenseSetup('petugas_sarpras');
        $this->actingAs($petugas)->post(route('transactions.store'), $this->expensePayload($masters))->assertRedirect();
        $transaction = Transaction::query()->firstOrFail();
        $this->post(route('transactions.submit', $transaction))->assertRedirect();
        $this->post(route('transactions.post', $transaction))->assertForbidden();

        $financeUser = $this->userWithRole('keuangan_sarpras');
        $masters['cash_account']->movements()->create([
            'kind' => 'income', 'direction' => 'in', 'amount' => '1000.00', 'idempotency_key' => 'initial:'.$transaction->id,
            'occurred_at' => now(), 'recorded_at' => now(), 'created_by' => $financeUser->id,
        ]);
        Storage::fake('local');
        $transaction->attachments()->create([
            'original_name' => 'receipt.pdf', 'disk' => 'local', 'path' => 'transactions/test/receipt.pdf',
            'mime_type' => 'application/pdf', 'size_bytes' => 1024, 'uploaded_by' => $petugas->id,
        ]);
        Storage::disk('local')->put('transactions/test/receipt.pdf', "%PDF-1.4\nReceipt");
        $this->actingAs($financeUser)->post(route('transactions.post', $transaction))->assertRedirect();
        $postedUpdatePayload = $this->expensePayload($masters);
        $postedUpdatePayload['change_reason'] = 'Tes edit pada transaksi posted.';
        $this->put(route('transactions.update', $transaction), $postedUpdatePayload)->assertSessionHasErrors('transaction');

        $this->assertSame('posted', $transaction->fresh()->status);
        $this->assertDatabaseCount('cash_movements', 2);
    }

    public function test_future_dated_income_and_expense_cannot_be_posted(): void
    {
        [$financeUser, $masters] = $this->expenseSetup('keuangan_sarpras');
        $futureAt = now()->addDay()->format('Y-m-d H:i:s');
        $incomePayload = [
            'type' => 'income', 'income_mode' => 'direct', 'income_amount' => '50.00',
            'occurred_at' => $futureAt, 'description' => 'Penerimaan masa depan.',
            'responsible_user_id' => $financeUser->id, 'destination_cash_account_id' => $masters['cash_account']->id,
            'payment_method_id' => $masters['payment_method']->id,
        ];
        $this->actingAs($financeUser)->post(route('transactions.store'), $incomePayload)->assertRedirect();
        $income = Transaction::query()->where('type', 'income')->firstOrFail();
        $this->post(route('transactions.submit', $income))->assertRedirect();
        $this->post(route('transactions.post', $income))->assertSessionHasErrors('occurred_at');

        $expensePayload = $this->expensePayload($masters);
        $expensePayload['occurred_at'] = $futureAt;
        $this->post(route('transactions.store'), $expensePayload)->assertRedirect();
        $expense = Transaction::query()->where('type', 'expense')->firstOrFail();
        $this->post(route('transactions.submit', $expense))->assertRedirect();
        $this->post(route('transactions.post', $expense))->assertSessionHasErrors('occurred_at');

        $this->assertSame('submitted', $income->fresh()->status);
        $this->assertSame('submitted', $expense->fresh()->status);
        $this->assertDatabaseCount('cash_movements', 0);
    }

    public function test_expense_posting_rejects_insufficient_balance_then_allows_finance_override(): void
    {
        Storage::fake('local');
        [$financeUser, $masters] = $this->expenseSetup('keuangan_sarpras');
        $masters['cash_account']->movements()->create([
            'kind' => 'income', 'direction' => 'in', 'amount' => '50.00', 'idempotency_key' => 'opening:'.$masters['cash_account']->id,
            'occurred_at' => now(), 'recorded_at' => now(), 'created_by' => $financeUser->id,
        ]);
        $payload = $this->expensePayload($masters);
        $payload['items'][0]['unit_price'] = '100.00';
        $payload['attachments'] = [UploadedFile::fake()->createWithContent('nota.pdf', "%PDF-1.4\nNota")];
        $this->actingAs($financeUser)->post(route('transactions.store'), $payload)->assertRedirect();
        $transaction = Transaction::query()->firstOrFail();
        $this->post(route('transactions.submit', $transaction))->assertRedirect();

        $this->post(route('transactions.post', $transaction))->assertSessionHasErrors('allow_negative');
        $this->post(route('transactions.post', $transaction), ['allow_negative' => 1])->assertSessionHasErrors('override_reason');
        $this->post(route('transactions.post', $transaction), [
            'allow_negative' => 1,
            'override_reason' => 'Kebutuhan mendesak sesuai persetujuan keuangan.',
        ])->assertRedirect();

        $this->assertSame('-200.00', $masters['cash_account']->ledgerBalance());
        $this->assertTrue($transaction->fresh()->negative_balance_override);
        $this->assertSame('200.00', $transaction->fresh()->negative_balance_shortfall);
        $this->assertSame($financeUser->id, $transaction->fresh()->negative_balance_override_by);
        $this->assertDatabaseHas('audit_logs', ['action' => 'transaction_posted']);
    }

    public function test_creator_can_cancel_own_draft_with_reason_without_a_ledger_movement(): void
    {
        [$petugas, $masters] = $this->expenseSetup('petugas_sarpras');
        $this->actingAs($petugas)->post(route('transactions.store'), $this->expensePayload($masters))->assertRedirect();
        $transaction = Transaction::query()->firstOrFail();

        $this->post(route('transactions.cancel', $transaction), ['reason' => 'Pembelian tidak jadi dilakukan.'])->assertRedirect();

        $this->assertSame('cancelled', $transaction->fresh()->status);
        $this->assertSame($petugas->id, $transaction->fresh()->cancelled_by);
        $this->assertDatabaseCount('cash_movements', 0);
        $this->assertDatabaseHas('audit_logs', ['action' => 'transaction_cancelled']);
    }

    public function test_finance_can_cancel_another_users_draft_with_a_reason(): void
    {
        [$petugas, $masters] = $this->expenseSetup('petugas_sarpras');
        $this->actingAs($petugas)->post(route('transactions.store'), $this->expensePayload($masters))->assertRedirect();
        $transaction = Transaction::query()->firstOrFail();
        $financeUser = $this->userWithRole('keuangan_sarpras');

        $this->actingAs($financeUser)->post(route('transactions.cancel', $transaction), [
            'reason' => 'Draft dibatalkan setelah konfirmasi pemohon.',
        ])->assertRedirect();

        $this->assertSame('cancelled', $transaction->fresh()->status);
        $this->assertSame($financeUser->id, $transaction->fresh()->cancelled_by);
        $this->assertDatabaseCount('cash_movements', 0);
    }

    public function test_finance_editing_another_users_draft_requires_reason_and_audits_before_after(): void
    {
        [$petugas, $masters] = $this->expenseSetup('petugas_sarpras');
        $this->actingAs($petugas)->post(route('transactions.store'), $this->expensePayload($masters))->assertRedirect();
        $transaction = Transaction::query()->firstOrFail();
        $financeUser = $this->userWithRole('keuangan_sarpras');
        $payload = $this->expensePayload($masters);
        $payload['description'] = 'Diperbaiki oleh Keuangan.';

        $this->actingAs($financeUser)->put(route('transactions.update', $transaction), $payload)->assertSessionHasErrors('change_reason');
        $payload['change_reason'] = 'Perbaikan data rekening pada draft.';
        $this->put(route('transactions.update', $transaction), $payload)->assertRedirect();

        $this->assertSame('Diperbaiki oleh Keuangan.', $transaction->fresh()->description);
        $this->assertDatabaseHas('audit_logs', ['action' => 'transaction_updated']);
        $audit = AuditLog::query()->where('action', 'transaction_updated')->firstOrFail();
        $this->assertSame('Draft pengeluaran.', $audit->old_values['description']);
        $this->assertSame('Perbaikan data rekening pada draft.', $audit->new_values['change_reason']);
    }

    public function test_changing_occurred_month_issues_a_new_number_from_existing_generator(): void
    {
        [$financeUser, $masters] = $this->expenseSetup('keuangan_sarpras');
        $payload = $this->expensePayload($masters);
        $payload['occurred_at'] = '2026-05-31 10:00:00';
        $this->actingAs($financeUser)->post(route('transactions.store'), $payload)->assertRedirect();
        $transaction = Transaction::query()->firstOrFail();
        $oldNumber = $transaction->number;
        $payload['occurred_at'] = '2026-06-01 10:00:00';

        $this->put(route('transactions.update', $transaction), $payload)->assertRedirect();

        $this->assertSame('EXP/202606/0001', $transaction->fresh()->number);
        $this->assertNotSame($oldNumber, $transaction->fresh()->number);
        $this->assertDatabaseHas('audit_logs', ['action' => 'transaction_updated']);
    }

    public function test_inactive_master_data_is_rejected_for_new_transactions(): void
    {
        [$petugas, $masters] = $this->expenseSetup('petugas_sarpras');
        $masters['fund_source']->update(['is_active' => false]);

        $this->actingAs($petugas)->post(route('transactions.store'), $this->expensePayload($masters))
            ->assertSessionHasErrors('fund_source_id');

        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_subcategory_is_required_for_its_category_and_cannot_be_borrowed_from_another_category(): void
    {
        [$petugas, $masters] = $this->expenseSetup('petugas_sarpras');
        $requiredSubcategory = Subcategory::factory()->for($masters['category'])->create();
        $payload = $this->expensePayload($masters);

        $this->actingAs($petugas)->post(route('transactions.store'), $payload)
            ->assertSessionHasErrors('items.0.subcategory_id');

        $otherCategory = Category::factory()->create();
        $otherSubcategory = Subcategory::factory()->for($otherCategory)->create();
        $payload['items'][0]['subcategory_id'] = $otherSubcategory->id;

        $this->post(route('transactions.store'), $payload)
            ->assertSessionHasErrors('items.0.subcategory_id');

        $payload['items'][0]['subcategory_id'] = $requiredSubcategory->id;
        $this->post(route('transactions.store'), $payload)->assertRedirect();
        $this->assertDatabaseCount('transactions', 1);
    }

    public function test_authorized_users_can_render_transaction_forms_details_and_review_queue(): void
    {
        [$financeUser, $masters] = $this->expenseSetup('keuangan_sarpras');
        $payload = $this->expensePayload($masters);
        $this->actingAs($financeUser)->get(route('transactions.index'))->assertOk();
        $this->get(route('transactions.create', ['type' => 'expense']))->assertOk();
        $this->get(route('transactions.create', ['type' => 'income']))->assertOk();

        $this->post(route('transactions.store'), $payload)->assertRedirect();
        $transaction = Transaction::query()->firstOrFail();
        $this->get(route('transactions.show', $transaction))->assertOk()->assertSee($transaction->number);
        $this->get(route('transactions.review-queue'))->assertOk();
    }

    /** @return array{User, array{cash_account: CashAccount, payment_method: PaymentMethod, fund_source: FundSource, category: Category}} */
    private function expenseSetup(string $role): array
    {
        $user = $this->userWithRole($role);
        $cashAccount = CashAccount::factory()->create();
        $paymentMethod = PaymentMethod::factory()->create();
        $fundSource = FundSource::factory()->create();
        $category = Category::factory()->create();
        Subcategory::factory()->for($category)->create(['is_active' => false]);

        return [$user, [
            'cash_account' => $cashAccount,
            'payment_method' => $paymentMethod,
            'fund_source' => $fundSource,
            'category' => $category,
        ]];
    }

    /** @param array{cash_account: CashAccount, payment_method: PaymentMethod, fund_source: FundSource, category: Category} $masters
     * @return array<string, mixed>
     */
    private function expensePayload(array $masters): array
    {
        return [
            'type' => 'expense',
            'occurred_at' => '2026-05-14 11:30:00',
            'description' => 'Draft pengeluaran.',
            'responsible_user_id' => User::query()->firstOrFail()->id,
            'source_cash_account_id' => $masters['cash_account']->id,
            'payment_method_id' => $masters['payment_method']->id,
            'fund_source_id' => $masters['fund_source']->id,
            'items' => [[
                'description' => 'Perlengkapan kantor',
                'category_id' => $masters['category']->id,
                'quantity' => '2.5000',
                'unit' => 'paket',
                'unit_price' => '100.00',
            ]],
        ];
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('name', $role)->firstOrFail());

        return $user;
    }
}
