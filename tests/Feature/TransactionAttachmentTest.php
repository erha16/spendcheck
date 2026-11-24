<?php

namespace Tests\Feature;

use App\Models\CashAccount;
use App\Models\Category;
use App\Models\FundSource;
use App\Models\PaymentMethod;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\TransactionAttachment;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Tests\TestCase;

class TransactionAttachmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
    }

    public function test_authorized_finance_can_download_a_private_attachment_without_receiving_its_storage_path(): void
    {
        Storage::fake('local');
        $financeUser = $this->userWithRole('keuangan_sarpras');
        $transaction = Transaction::factory()->create();
        $attachment = $this->attachmentFor($transaction, $financeUser, 'transactions/private/receipt.pdf');
        Storage::disk('local')->put($attachment->path, "%PDF-1.4\nReceipt");

        $response = $this->actingAs($financeUser)->get(route('transactions.attachments', [
            'transaction' => $transaction,
            'attachment' => $attachment,
            'mode' => 'download',
        ]));

        $response->assertOk()->assertHeader('content-disposition', 'attachment; filename=receipt.pdf');
        $response->assertStreamedContent("%PDF-1.4\nReceipt");
        $this->assertStringNotContainsString($attachment->path, $response->streamedContent());

        $this->get(route('transactions.attachments', [
            'transaction' => $transaction, 'attachment' => $attachment, 'mode' => 'preview',
        ]))->assertOk()->assertHeader('content-disposition', 'inline; filename=receipt.pdf');
    }

    public function test_petugas_cannot_download_another_users_attachment(): void
    {
        Storage::fake('local');
        $petugas = $this->userWithRole('petugas_sarpras');
        $owner = User::factory()->create();
        $transaction = Transaction::factory()->create(['created_by' => $owner->id]);
        $attachment = $this->attachmentFor($transaction, $owner, 'transactions/private/other.pdf');
        Storage::disk('local')->put($attachment->path, "%PDF-1.4\nPrivate");

        $this->actingAs($petugas)->get(route('transactions.attachments', [
            'transaction' => $transaction,
            'attachment' => $attachment,
            'mode' => 'download',
        ]))->assertNotFound();
    }

    public function test_attachment_routes_cannot_use_an_attachment_from_a_different_transaction(): void
    {
        Storage::fake('local');
        $financeUser = $this->userWithRole('keuangan_sarpras');
        $transaction = Transaction::factory()->create();
        $otherTransaction = Transaction::factory()->create();
        $attachment = $this->attachmentFor($otherTransaction, $financeUser, 'transactions/private/another.pdf');
        Storage::disk('local')->put($attachment->path, "%PDF-1.4\nPrivate");

        $this->actingAs($financeUser)->get(route('transactions.attachments', [
            'transaction' => $transaction,
            'attachment' => $attachment,
            'mode' => 'download',
        ]))->assertNotFound();
    }

    public function test_file_with_unsupported_content_is_rejected(): void
    {
        Storage::fake('local');
        $petugas = $this->userWithRole('petugas_sarpras');
        $payload = $this->expensePayload($petugas);
        $payload['attachments'] = [UploadedFile::fake()->createWithContent('nota.txt', 'plain text')];

        $this->actingAs($petugas)->post(route('transactions.store'), $payload)->assertSessionHasErrors('attachments.0');

        $this->assertDatabaseCount('transactions', 0);
        $this->assertDatabaseCount('transaction_attachments', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_file_larger_than_ten_megabytes_is_rejected(): void
    {
        Storage::fake('local');
        $petugas = $this->userWithRole('petugas_sarpras');
        $payload = $this->expensePayload($petugas);
        $payload['attachments'] = [UploadedFile::fake()->createWithContent('nota.pdf', "%PDF-1.4\n".str_repeat('0', 10 * 1024 * 1024 + 1))];

        $this->actingAs($petugas)->post(route('transactions.store'), $payload)->assertSessionHasErrors('attachments.0');

        $this->assertDatabaseCount('transaction_attachments', 0);
    }

    public function test_heic_attachment_remains_downloadable_and_posted_evidence_is_immutable(): void
    {
        Storage::fake('local');
        $financeUser = $this->userWithRole('keuangan_sarpras');
        $transaction = Transaction::factory()->create(['created_by' => $financeUser->id, 'status' => 'posted']);
        $path = 'transactions/'.$transaction->public_id.'/proof.heic';
        $contents = 'heic sample';
        Storage::disk('local')->put($path, $contents);
        $attachment = $transaction->attachments()->create([
            'original_name' => 'proof.heic', 'disk' => 'local', 'path' => $path, 'mime_type' => 'image/heic',
            'size_bytes' => strlen($contents), 'checksum' => hash('sha256', $contents), 'uploaded_by' => $financeUser->id,
        ]);

        $this->actingAs($financeUser)->get(route('transactions.attachments', [
            'transaction' => $transaction, 'attachment' => $attachment, 'mode' => 'download',
        ]))->assertOk()->assertHeader('content-disposition', 'attachment; filename=proof.heic');

        try {
            $attachment->update(['original_name' => 'changed.pdf']);
            $this->fail('A posted transaction attachment was modified.');
        } catch (LogicException) {
            $this->assertSame('proof.heic', $attachment->fresh()->original_name);
        }
        try {
            $attachment->delete();
            $this->fail('A posted transaction attachment was deleted.');
        } catch (LogicException) {
            $this->assertDatabaseHas('transaction_attachments', ['id' => $attachment->id]);
        }
    }

    private function attachmentFor(Transaction $transaction, User $uploader, string $path): TransactionAttachment
    {
        return $transaction->attachments()->create([
            'original_name' => basename($path),
            'disk' => 'local',
            'path' => $path,
            'mime_type' => 'application/pdf',
            'size_bytes' => 20,
            'checksum' => hash('sha256', 'file content'),
            'uploaded_by' => $uploader->id,
        ]);
    }

    /** @return array<string, mixed> */
    private function expensePayload(User $user): array
    {
        $cashAccount = CashAccount::factory()->create();
        $paymentMethod = PaymentMethod::factory()->create();
        $fundSource = FundSource::factory()->create();
        $category = Category::factory()->create();

        return [
            'type' => 'expense',
            'occurred_at' => '2026-06-01',
            'description' => 'Bukti transaksi.',
            'responsible_user_id' => $user->id,
            'source_cash_account_id' => $cashAccount->id,
            'payment_method_id' => $paymentMethod->id,
            'fund_source_id' => $fundSource->id,
            'items' => [[
                'description' => 'Nota belanja',
                'category_id' => $category->id,
                'quantity' => '1.0000',
                'unit' => 'unit',
                'unit_price' => '100.00',
            ]],
        ];
    }

    private function userWithRole(string $roleName): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('name', $roleName)->firstOrFail());

        return $user;
    }
}
