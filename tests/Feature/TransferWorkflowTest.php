<?php

namespace Tests\Feature;

use App\Models\CashAccount;
use App\Models\CashMovement;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TransferWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
    }

    public function test_finance_can_create_a_transfer_draft_with_private_evidence_and_no_ledger_change(): void
    {
        Storage::fake('local');
        $finance = $this->userWithRole('keuangan_sarpras');
        $source = CashAccount::factory()->create();
        $destination = CashAccount::factory()->create();

        $this->actingAs($finance)->get(route('transactions.create', ['type' => 'transfer']))->assertOk();
        $this->actingAs($finance)->post(route('transactions.store'), $this->transferPayload($source, $destination, true))
            ->assertRedirect();
        $transaction = Transaction::query()->firstOrFail();

        $this->assertSame('TRF/202605/0001', $transaction->number);
        $this->assertSame('draft', $transaction->status);
        $this->assertNull($transaction->responsible_user_id);
        $this->assertNull($transaction->payment_method_id);
        $this->assertSame('Catatan pemindahan dana.', $transaction->notes);
        $this->assertSame('0.00', $source->ledgerBalance());
        $this->assertSame('0.00', $destination->ledgerBalance());
        $this->assertDatabaseCount('transaction_items', 0);
        $this->assertDatabaseCount('cash_movements', 0);
        $this->assertDatabaseHas('audit_logs', ['action' => 'transaction_created', 'subject_id' => $transaction->id]);
        $this->assertDatabaseHas('transaction_attachments', ['transaction_id' => $transaction->id, 'disk' => 'local']);
        $this->assertCount(1, Storage::disk('local')->allFiles('transactions/'.$transaction->public_id));
        $this->get(route('transactions.show', $transaction))->assertOk()->assertSee('Akun kas sumber')->assertSee('Nominal transfer');
        $this->get(route('transactions.index', ['type' => 'transfer']))->assertOk()->assertSee($transaction->number);
    }

    public function test_finance_can_post_transfer_as_two_equal_atomic_movements_once(): void
    {
        Storage::fake('local');
        $finance = $this->userWithRole('keuangan_sarpras');
        $source = CashAccount::factory()->create();
        $destination = CashAccount::factory()->create();
        $this->addOpeningMovement($source, $finance, '500.00', 'opening-source-'.$source->id, '2026-05-01 09:00:00');
        $this->addOpeningMovement($destination, $finance, '50.00', 'opening-destination-'.$destination->id, '2026-05-01 09:00:00');

        $this->actingAs($finance)->post(route('transactions.store'), $this->transferPayload($source, $destination, true))->assertRedirect();
        $transaction = Transaction::query()->firstOrFail();
        $this->post(route('transactions.submit', $transaction))->assertRedirect();
        $this->post(route('transactions.post', $transaction))->assertRedirect();
        $this->post(route('transactions.post', $transaction))->assertSessionHasErrors('transaction');
        $this->get(route('transactions.edit', $transaction))->assertNotFound();

        $movements = $transaction->movements()->orderBy('transfer_leg')->get();
        $this->assertSame('posted', $transaction->fresh()->status);
        $this->assertSame($finance->id, $transaction->fresh()->posted_by);
        $this->assertCount(2, $movements);
        $this->assertSame(['transfer_in', 'transfer_out'], $movements->pluck('kind')->sort()->values()->all());
        $this->assertSame(['100.00', '100.00'], $movements->pluck('amount')->all());
        $this->assertSame(['in', 'out'], $movements->pluck('direction')->all());
        $this->assertSame(['in', 'out'], $movements->pluck('transfer_leg')->all());
        $this->assertSame('400.00', $source->ledgerBalance());
        $this->assertSame('150.00', $destination->ledgerBalance());
        $this->assertDatabaseCount('cash_movements', 4);
        $this->assertDatabaseHas('audit_logs', ['action' => 'transaction_posted', 'subject_id' => $transaction->id]);
    }

    public function test_failed_second_transfer_movement_rolls_back_the_first_movement_and_posted_status(): void
    {
        Storage::fake('local');
        $finance = $this->userWithRole('keuangan_sarpras');
        $source = CashAccount::factory()->create();
        $destination = CashAccount::factory()->create();
        $this->addOpeningMovement($source, $finance, '500.00', 'opening-source-'.$source->id, '2026-05-01 09:00:00');
        $this->actingAs($finance)->post(route('transactions.store'), $this->transferPayload($source, $destination, true))->assertRedirect();
        $transaction = Transaction::query()->firstOrFail();
        $this->post(route('transactions.submit', $transaction))->assertRedirect();
        CashMovement::factory()->create([
            'cash_account_id' => $destination->id,
            'idempotency_key' => 'transaction-transfer-in:'.$transaction->public_id,
            'created_by' => $finance->id,
        ]);
        $this->assertDatabaseHas('cash_movements', ['idempotency_key' => 'transaction-transfer-in:'.$transaction->public_id]);

        $this->post(route('transactions.post', $transaction))->assertInternalServerError();

        $this->assertSame('submitted', $transaction->fresh()->status);
        $this->assertSame(0, $transaction->movements()->count());
        $this->assertDatabaseMissing('audit_logs', ['action' => 'transaction_posted', 'subject_id' => $transaction->id]);
    }

    public function test_transfer_rejects_same_account_invalid_amount_and_missing_evidence(): void
    {
        $finance = $this->userWithRole('keuangan_sarpras');
        $source = CashAccount::factory()->create();
        $destination = CashAccount::factory()->create();
        $payload = $this->transferPayload($source, $destination, false);

        $this->actingAs($finance)->post(route('transactions.store'), [...$payload, 'destination_cash_account_id' => $source->id])
            ->assertSessionHasErrors('destination_cash_account_id');
        $this->post(route('transactions.store'), [...$payload, 'transfer_amount' => '0.00'])
            ->assertSessionHasErrors('transfer_amount');
        $this->post(route('transactions.store'), $payload)->assertRedirect();
        $transaction = Transaction::query()->firstOrFail();
        $this->post(route('transactions.submit', $transaction))->assertRedirect();
        $this->post(route('transactions.post', $transaction))->assertSessionHasErrors('transaction');

        $this->assertSame('submitted', $transaction->fresh()->status);
        $this->assertDatabaseCount('cash_movements', 0);
    }

    public function test_transfer_cannot_be_posted_before_its_future_occurred_at(): void
    {
        Storage::fake('local');
        $this->travelTo(now());
        $finance = $this->userWithRole('keuangan_sarpras');
        $source = CashAccount::factory()->create();
        $destination = CashAccount::factory()->create();
        $this->addOpeningMovement($source, $finance, '500.00', 'opening-source-'.$source->id, now()->toDateTimeString());
        $payload = $this->transferPayload($source, $destination, true);
        $payload['occurred_at'] = now()->addDay()->format('Y-m-d H:i:s');
        $this->actingAs($finance)->post(route('transactions.store'), $payload)->assertRedirect();
        $transaction = Transaction::query()->firstOrFail();
        $this->post(route('transactions.submit', $transaction))->assertRedirect();
        $this->post(route('transactions.post', $transaction))->assertSessionHasErrors('occurred_at');

        $this->assertSame('submitted', $transaction->fresh()->status);
        $this->assertDatabaseCount('cash_movements', 1);
    }

    public function test_transfer_with_insufficient_balance_cannot_use_negative_override(): void
    {
        Storage::fake('local');
        $finance = $this->userWithRole('keuangan_sarpras');
        $source = CashAccount::factory()->create();
        $destination = CashAccount::factory()->create();
        $this->addOpeningMovement($source, $finance, '50.00', 'opening-source-'.$source->id, '2026-05-01 09:00:00');
        $this->actingAs($finance)->post(route('transactions.store'), $this->transferPayload($source, $destination, true))->assertRedirect();
        $transaction = Transaction::query()->firstOrFail();
        $this->post(route('transactions.submit', $transaction))->assertRedirect();

        $this->post(route('transactions.post', $transaction), [
            'allow_negative' => true,
            'override_reason' => 'Tidak boleh digunakan pada transfer.',
        ])->assertSessionHasErrors('source_cash_account_id');

        $this->assertSame('50.00', $source->ledgerBalance());
        $this->assertDatabaseCount('cash_movements', 1);
    }

    public function test_finance_can_use_an_inactive_source_only_to_transfer_available_balance_to_an_active_account(): void
    {
        Storage::fake('local');
        $finance = $this->userWithRole('keuangan_sarpras');
        $inactiveSource = CashAccount::factory()->create(['is_active' => false]);
        $activeDestination = CashAccount::factory()->create(['is_active' => true]);
        $inactiveDestination = CashAccount::factory()->create(['is_active' => false]);
        $this->addOpeningMovement($inactiveSource, $finance, '500.00', 'opening-source-'.$inactiveSource->id, '2026-05-01 09:00:00');
        $payload = $this->transferPayload($inactiveSource, $activeDestination, true);

        $this->actingAs($finance)->post(route('transactions.store'), [...$payload, 'destination_cash_account_id' => $inactiveDestination->id])
            ->assertSessionHasErrors('destination_cash_account_id');
        $this->post(route('transactions.store'), $payload)->assertRedirect();
        $transaction = Transaction::query()->firstOrFail();
        $this->post(route('transactions.submit', $transaction))->assertRedirect();
        $this->post(route('transactions.post', $transaction))->assertRedirect();

        $this->assertSame('400.00', $inactiveSource->ledgerBalance());
        $this->assertSame('100.00', $activeDestination->ledgerBalance());
        $this->assertSame('posted', $transaction->fresh()->status);
    }

    public function test_transfer_review_revision_and_resubmission_use_existing_review_workflow(): void
    {
        Storage::fake('local');
        $finance = $this->userWithRole('keuangan_sarpras');
        $source = CashAccount::factory()->create();
        $destination = CashAccount::factory()->create();
        $this->actingAs($finance)->post(route('transactions.store'), $this->transferPayload($source, $destination, true))->assertRedirect();
        $transaction = Transaction::query()->firstOrFail();
        $this->post(route('transactions.submit', $transaction))->assertRedirect();
        $this->get(route('transactions.review-queue'))->assertOk()->assertSee($transaction->number);
        $this->post(route('transactions.review', $transaction), ['decision' => 'revision', 'reason' => 'Tambahkan referensi transfer.'])->assertRedirect();
        $this->put(route('transactions.update', $transaction), [...$this->transferPayload($source, $destination, false), 'notes' => 'Referensi bank BCA-123.'])->assertRedirect();
        $this->post(route('transactions.submit', $transaction))->assertRedirect();

        $this->assertSame('submitted', $transaction->fresh()->status);
        $this->assertSame('Referensi bank BCA-123.', $transaction->fresh()->notes);
        $this->assertDatabaseHas('audit_logs', ['action' => 'transaction_revision_requested', 'subject_id' => $transaction->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'transaction_submitted', 'subject_id' => $transaction->id]);
    }

    public function test_role_without_transfer_permission_cannot_create_or_post_transfer(): void
    {
        Storage::fake('local');
        $petugas = $this->userWithRole('petugas_sarpras');
        $finance = $this->userWithRole('keuangan_sarpras');
        $source = CashAccount::factory()->create();
        $destination = CashAccount::factory()->create();
        $this->addOpeningMovement($source, $finance, '500.00', 'opening-source-'.$source->id, '2026-05-01 09:00:00');

        $this->actingAs($petugas)->get(route('transactions.create', ['type' => 'transfer']))->assertForbidden();
        $this->post(route('transactions.store'), $this->transferPayload($source, $destination, true))->assertForbidden();
        $this->actingAs($finance)->post(route('transactions.store'), $this->transferPayload($source, $destination, true))->assertRedirect();
        $transaction = Transaction::query()->firstOrFail();
        $this->post(route('transactions.submit', $transaction))->assertRedirect();
        $this->actingAs($petugas)->post(route('transactions.post', $transaction))->assertForbidden();

        $this->assertSame('submitted', $transaction->fresh()->status);
        $this->assertSame(0, $transaction->movements()->count());
    }

    private function transferPayload(CashAccount $source, CashAccount $destination, bool $withEvidence): array
    {
        $payload = [
            'type' => 'transfer',
            'occurred_at' => '2026-05-14 11:30:00',
            'description' => 'Pemindahan dana antar akun.',
            'notes' => 'Catatan pemindahan dana.',
            'source_cash_account_id' => $source->id,
            'destination_cash_account_id' => $destination->id,
            'transfer_amount' => '100.00',
        ];

        if ($withEvidence) {
            $payload['attachments'] = [UploadedFile::fake()->createWithContent('bukti-transfer.pdf', "%PDF-1.4\nBukti transfer")];
        }

        return $payload;
    }

    private function addOpeningMovement(CashAccount $account, User $creator, string $amount, string $key, string $occurredAt): void
    {
        CashMovement::create([
            'cash_account_id' => $account->id,
            'kind' => 'opening_balance',
            'direction' => 'in',
            'amount' => $amount,
            'idempotency_key' => $key,
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
