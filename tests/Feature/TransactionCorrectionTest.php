<?php

namespace Tests\Feature;

use App\Models\CashAccount;
use App\Models\CashMovement;
use App\Models\Category;
use App\Models\FundSource;
use App\Models\PaymentMethod;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\TransactionRevision;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Tests\TestCase;

class TransactionCorrectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
        Storage::fake('local');
    }

    public function test_finance_corrects_posted_income_with_reversal_revision_and_new_posting(): void
    {
        $finance = $this->userWithRole('keuangan_sarpras');
        $originalAccount = CashAccount::factory()->create();
        $replacementAccount = CashAccount::factory()->create();
        $original = $this->postedTransaction($finance, 'income', $originalAccount, null, '100.00');
        $originalMovement = $this->movement($original, $finance, $originalAccount, 'income', 'in', '100.00', 'income-original');
        $method = PaymentMethod::factory()->create();
        $this->actingAs($finance)->get(route('transactions.corrections.create', $original))
            ->assertOk()->assertSee($original->number)->assertSee('correction_reason');

        $response = $this->actingAs($finance)->post(route('transactions.corrections.store', $original), [
            'type' => 'income', 'occurred_at' => '2026-05-15 12:30:00', 'description' => 'Pemasukan terkoreksi',
            'responsible_user_id' => $finance->id, 'payment_method_id' => $method->id,
            'destination_cash_account_id' => $replacementAccount->id, 'income_mode' => 'direct', 'income_amount' => '125.00',
            'correction_reason' => 'Nilai penerimaan awal keliru.',
        ]);

        $replacement = Transaction::query()->where('reversal_of_id', $original->id)->firstOrFail();
        $reversal = $originalMovement->reversals()->sole();
        $response->assertRedirect(route('transactions.show', $replacement));
        $this->assertSame('reversed', $original->fresh()->status);
        $this->assertSame('posted', $replacement->status);
        $this->assertNotSame($original->number, $replacement->number);
        $this->assertSame('out', $reversal->direction);
        $this->assertSame($originalMovement->occurred_at->toDateTimeString(), $reversal->occurred_at->toDateTimeString());
        $this->assertSame('2026-05-15 12:30:00', $replacement->movements()->sole()->occurred_at->toDateTimeString());
        $this->assertSame('125.00', $replacementAccount->ledgerBalance());
        $this->assertSame('0.00', $originalAccount->ledgerBalance());
        $revision = TransactionRevision::query()->sole();
        $this->assertSame('Pemasukan terkoreksi', $revision->after_values['description']);
        $this->assertSame('Pemasukan asli.', $revision->before_values['description']);
        $this->assertSame('Nilai penerimaan awal keliru.', $revision->reason);
        $this->assertDatabaseHas('audit_logs', ['action' => 'transaction_corrected', 'subject_id' => $original->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'transaction_replacement_posted', 'subject_id' => $replacement->id]);
        $this->assertDatabaseCount('cash_movements', 3);
        $this->get(route('transactions.show', $original))->assertOk()->assertSee('Nilai penerimaan awal keliru.')->assertSee($replacement->number);
        $this->get(route('transactions.show', $replacement))->assertOk()->assertSee($original->number);
        $this->post(route('transactions.corrections.store', $original), [
            'type' => 'income', 'occurred_at' => '2026-05-15 12:30:00', 'description' => 'Percobaan duplikat',
            'responsible_user_id' => $finance->id, 'payment_method_id' => $method->id,
            'destination_cash_account_id' => $replacementAccount->id, 'income_mode' => 'direct', 'income_amount' => '125.00',
            'correction_reason' => 'Percobaan koreksi berulang.',
        ])->assertForbidden();
        $this->assertDatabaseCount('transaction_revisions', 1);
        $this->assertDatabaseCount('cash_movements', 3);

        try {
            $revision->update(['reason' => 'changed']);
            $this->fail('Revision update should be rejected.');
        } catch (LogicException) {
            $this->assertSame('Nilai penerimaan awal keliru.', $revision->fresh()->reason);
        }
        try {
            $revision->delete();
            $this->fail('Revision deletion should be rejected.');
        } catch (LogicException) {
            $this->assertDatabaseCount('transaction_revisions', 1);
        }
        $this->assertSame('in', $originalMovement->fresh()->direction);
        $this->assertNull($originalMovement->fresh()->reversal_of_id);
    }

    public function test_correction_reason_is_required_and_other_roles_cannot_correct(): void
    {
        $finance = $this->userWithRole('keuangan_sarpras');
        $original = $this->postedTransaction($finance, 'income', CashAccount::factory()->create(), null, '100.00');
        $method = PaymentMethod::factory()->create();
        $payload = [
            'type' => 'income', 'occurred_at' => now()->subDay()->format('Y-m-d H:i:s'), 'description' => 'Penerimaan baru',
            'responsible_user_id' => $finance->id, 'payment_method_id' => $method->id,
            'destination_cash_account_id' => CashAccount::factory()->create()->id, 'income_mode' => 'direct', 'income_amount' => '90.00',
        ];
        $this->actingAs($finance)->post(route('transactions.corrections.store', $original), $payload)->assertSessionHasErrors('correction_reason');
        $this->assertSame('posted', $original->fresh()->status);
        $this->assertDatabaseCount('transaction_revisions', 0);

        foreach (['admin_system', 'petugas_sarpras', 'kepala_sarpras', 'divisi_keuangan'] as $role) {
            $user = $this->userWithRole($role);
            $this->actingAs($user)->post(route('transactions.corrections.store', $original), [...$payload, 'correction_reason' => 'Data awal salah.'])
                ->assertForbidden();
        }
    }

    public function test_transfer_correction_reverses_both_legs_and_posts_balanced_replacement(): void
    {
        $finance = $this->userWithRole('keuangan_sarpras');
        $source = CashAccount::factory()->create();
        $destination = CashAccount::factory()->create();
        $replacementSource = CashAccount::factory()->create();
        $replacementDestination = CashAccount::factory()->create();
        $this->openingMovement($finance, $source, '500.00', 'opening-source');
        $this->openingMovement($finance, $replacementSource, '500.00', 'opening-replacement-source');
        $original = $this->postedTransaction($finance, 'transfer', $source, $destination, '100.00');
        $oldOut = $this->movement($original, $finance, $source, 'transfer_out', 'out', '100.00', 'transfer-old-out', 'out');
        $oldIn = $this->movement($original, $finance, $destination, 'transfer_in', 'in', '100.00', 'transfer-old-in', 'in');
        $proof = UploadedFile::fake()->createWithContent('new-transfer.pdf', "%PDF-1.4\nnew proof");
        $this->actingAs($finance)->get(route('transactions.corrections.create', $original))->assertOk()->assertSee('Koreksi transfer');

        $this->actingAs($finance)->post(route('transactions.corrections.store', $original), [
            'type' => 'transfer', 'occurred_at' => '2026-05-16 13:00:00', 'description' => 'Transfer pengganti',
            'source_cash_account_id' => $replacementSource->id, 'destination_cash_account_id' => $replacementDestination->id,
            'transfer_amount' => '120.00', 'correction_reason' => 'Akun dan nominal perlu disesuaikan.', 'attachments' => [$proof],
        ])->assertRedirect();

        $this->assertSame('in', $oldOut->reversals()->sole()->direction);
        $this->assertSame('out', $oldIn->reversals()->sole()->direction);
        $replacement = Transaction::query()->where('reversal_of_id', $original->id)->firstOrFail();
        $this->assertSame(['transfer_in', 'transfer_out'], $replacement->movements()->orderBy('kind')->pluck('kind')->all());
        $this->assertSame('500.00', $source->ledgerBalance());
        $this->assertSame('0.00', $destination->ledgerBalance());
        $this->assertSame('380.00', $replacementSource->ledgerBalance());
        $this->assertSame('120.00', $replacementDestination->ledgerBalance());
    }

    public function test_failed_expense_replacement_rolls_back_reversal_and_original_status(): void
    {
        $finance = $this->userWithRole('keuangan_sarpras');
        $account = CashAccount::factory()->create();
        $category = Category::factory()->create();
        $fund = FundSource::factory()->create();
        $method = PaymentMethod::factory()->create();
        $original = $this->postedTransaction($finance, 'expense', $account, null, '100.00');
        $movement = $this->movement($original, $finance, $account, 'expense', 'out', '100.00', 'expense-original');
        $this->openingMovement($finance, $account, '500.00', 'expense-opening');

        $this->actingAs($finance)->post(route('transactions.corrections.store', $original), [
            'type' => 'expense', 'occurred_at' => '2026-05-17 13:00:00', 'description' => 'Expense replacement',
            'responsible_user_id' => $finance->id, 'payment_method_id' => $method->id,
            'source_cash_account_id' => $account->id, 'fund_source_id' => $fund->id,
            'items' => [['description' => 'Barang', 'category_id' => $category->id, 'quantity' => '1', 'unit' => 'unit', 'unit_price' => '90.00']],
            'correction_reason' => 'Perbaiki transaksi belanja.',
        ])->assertSessionHasErrors('transaction');

        $this->assertSame('posted', $original->fresh()->status);
        $this->assertSame(0, $movement->reversals()->count());
        $this->assertDatabaseCount('transaction_revisions', 0);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'transaction_corrected', 'subject_id' => $original->id]);
        $this->assertSame('400.00', $account->ledgerBalance());
    }

    public function test_expense_correction_uses_existing_negative_balance_override_rule(): void
    {
        $finance = $this->userWithRole('keuangan_sarpras');
        $account = CashAccount::factory()->create();
        $category = Category::factory()->create();
        $fund = FundSource::factory()->create();
        $method = PaymentMethod::factory()->create();
        $this->openingMovement($finance, $account, '100.00', 'expense-override-opening');
        $original = $this->postedTransaction($finance, 'expense', $account, null, '50.00');
        $oldMovement = $this->movement($original, $finance, $account, 'expense', 'out', '50.00', 'expense-override-original');
        TransactionItem::create(['transaction_id' => $original->id, 'description' => 'Item lama', 'category_id' => $category->id,
            'quantity' => '1.0000', 'unit' => 'unit', 'unit_price' => '50.00', 'subtotal' => '50.00']);
        $oldProofPath = 'transactions/'.$original->public_id.'/old.pdf';
        Storage::disk('local')->put($oldProofPath, "%PDF-1.4\nold");
        $original->attachments()->create(['original_name' => 'old.pdf', 'disk' => 'local', 'path' => $oldProofPath,
            'mime_type' => 'application/pdf', 'size_bytes' => 12, 'checksum' => hash('sha256', "%PDF-1.4\nold"), 'uploaded_by' => $finance->id]);
        $payload = [
            'type' => 'expense', 'occurred_at' => '2026-05-18 13:00:00', 'description' => 'Expense replacement',
            'responsible_user_id' => $finance->id, 'payment_method_id' => $method->id, 'source_cash_account_id' => $account->id,
            'fund_source_id' => $fund->id,
            'items' => [['description' => 'Barang baru', 'category_id' => $category->id, 'quantity' => '1', 'unit' => 'unit', 'unit_price' => '150.00']],
            'correction_reason' => 'Nilai belanja diperbaiki.',
            'attachments' => [UploadedFile::fake()->createWithContent('new.pdf', "%PDF-1.4\nnew")],
        ];

        $this->actingAs($finance)->post(route('transactions.corrections.store', $original), $payload)->assertSessionHasErrors('allow_negative');
        $this->assertSame('posted', $original->fresh()->status);
        $this->assertSame(0, $oldMovement->reversals()->count());

        $this->post(route('transactions.corrections.store', $original), [...$payload, 'allow_negative' => true, 'override_reason' => 'Koreksi menyisakan kewajiban kas.'])
            ->assertRedirect();
        $replacement = Transaction::query()->where('reversal_of_id', $original->id)->firstOrFail();
        $this->assertSame('reversed', $original->fresh()->status);
        $this->assertSame('in', $oldMovement->reversals()->sole()->direction);
        $this->assertSame('out', $oldMovement->fresh()->direction);
        $this->assertTrue($replacement->negative_balance_override);
        $this->assertSame('50.00', $replacement->negative_balance_shortfall);
        $this->assertSame('-50.00', $account->ledgerBalance());
    }

    private function postedTransaction(User $user, string $type, CashAccount $account, ?CashAccount $destination, string $amount): Transaction
    {
        return Transaction::factory()->create([
            'type' => $type, 'occurred_at' => '2026-05-14 11:30:00', 'description' => 'Pemasukan asli.',
            'responsible_user_id' => $type === 'transfer' ? null : $user->id, 'created_by' => $user->id,
            'payment_method_id' => $type === 'transfer' ? null : PaymentMethod::factory()->create()->id,
            'source_cash_account_id' => in_array($type, ['expense', 'transfer'], true) ? $account->id : null,
            'destination_cash_account_id' => in_array($type, ['income', 'transfer'], true) ? ($destination?->id ?? $account->id) : null,
            'total_amount' => $amount, 'status' => 'posted', 'posted_by' => $user->id, 'posted_at' => now(),
        ]);
    }

    private function movement(Transaction $transaction, User $user, CashAccount $account, string $kind, string $direction, string $amount, string $key, ?string $leg = null): CashMovement
    {
        return CashMovement::create(['cash_account_id' => $account->id, 'transaction_id' => $transaction->id, 'kind' => $kind,
            'direction' => $direction, 'transfer_leg' => $leg, 'amount' => $amount, 'idempotency_key' => $key,
            'occurred_at' => $transaction->occurred_at, 'recorded_at' => now(), 'created_by' => $user->id]);
    }

    private function openingMovement(User $user, CashAccount $account, string $amount, string $key): void
    {
        CashMovement::create(['cash_account_id' => $account->id, 'kind' => 'opening_balance', 'direction' => 'in', 'amount' => $amount,
            'idempotency_key' => $key, 'occurred_at' => '2026-05-01 09:00:00', 'recorded_at' => now(), 'created_by' => $user->id]);
    }

    private function userWithRole(string $roleName): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('name', $roleName)->firstOrFail());

        return $user;
    }
}
