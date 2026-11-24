<?php

namespace Tests\Feature;

use App\Models\CashAccount;
use App\Models\OperationalAdvance;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OperationalAdvanceAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
    }

    public function test_driver_can_create_submit_and_view_only_their_own_advance(): void
    {
        $driver = $this->userWithRole('driver');
        $otherDriver = $this->userWithRole('driver');
        $otherAdvance = $this->advanceFor($otherDriver);
        $cashAccount = CashAccount::factory()->create();

        $this->actingAs($driver)->post(route('operational-advances.store'), $this->advancePayload($cashAccount))
            ->assertRedirect();

        $advance = OperationalAdvance::query()->where('responsible_user_id', $driver->id)->firstOrFail();
        $this->assertSame('draft', $advance->status);
        $this->assertSame($driver->id, $advance->requester_user_id);
        $this->assertSame($driver->id, $advance->created_by);

        $this->post(route('operational-advances.submit', $advance))->assertRedirect();
        $this->assertSame('submitted', $advance->fresh()->status);
        $this->get(route('operational-advances.show', $advance))->assertOk()->assertSee($advance->number);
        $this->get(route('operational-advances.show', $otherAdvance))->assertNotFound();

        $this->get(route('operational-advances.index'))->assertOk()->assertSee($advance->number)->assertDontSee($otherAdvance->number);
        $this->assertDatabaseCount('cash_movements', 0);
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_petugas_can_only_cancel_their_own_unsubmitted_draft(): void
    {
        $petugas = $this->userWithRole('petugas_sarpras');
        $otherPetugas = $this->userWithRole('petugas_sarpras');
        $ownAdvance = $this->advanceFor($petugas);
        $otherAdvance = $this->advanceFor($otherPetugas);

        $this->actingAs($petugas)->post(route('operational-advances.cancel', $ownAdvance), ['reason' => 'Kebutuhan tidak jadi.'])
            ->assertRedirect();
        $this->assertSame('cancelled', $ownAdvance->fresh()->status);

        $this->post(route('operational-advances.cancel', $otherAdvance), ['reason' => 'Tidak berhak.'])->assertNotFound();
    }

    public function test_finance_can_approve_another_users_request_but_cannot_create_cash_events(): void
    {
        $requester = $this->userWithRole('petugas_sarpras');
        $finance = $this->userWithRole('keuangan_sarpras');
        $cashAccount = CashAccount::factory()->create();

        $this->actingAs($requester)->post(route('operational-advances.store'), $this->advancePayload($cashAccount))
            ->assertRedirect();
        $advance = OperationalAdvance::query()->where('requester_user_id', $requester->id)->firstOrFail();
        $this->post(route('operational-advances.submit', $advance))->assertRedirect();

        $this->actingAs($finance)->post(route('operational-advances.approve', $advance), ['approved_amount' => '800.00'])
            ->assertRedirect();

        $this->assertSame('approved', $advance->fresh()->status);
        $this->assertSame('800.00', $advance->fresh()->approved_amount);
        $this->assertSame($finance->id, $advance->fresh()->approved_by);
        $this->assertDatabaseHas('audit_logs', ['action' => 'operational_advance_approved', 'subject_id' => $advance->id]);
        $this->assertDatabaseCount('transactions', 0);
        $this->assertDatabaseCount('cash_movements', 0);
    }

    public function test_finance_cannot_approve_more_than_the_requested_amount(): void
    {
        $requester = $this->userWithRole('driver');
        $finance = $this->userWithRole('keuangan_sarpras');
        $cashAccount = CashAccount::factory()->create();

        $this->actingAs($requester)->post(route('operational-advances.store'), $this->advancePayload($cashAccount))->assertRedirect();
        $advance = OperationalAdvance::query()->where('requester_user_id', $requester->id)->firstOrFail();
        $this->post(route('operational-advances.submit', $advance))->assertRedirect();

        $this->actingAs($finance)->post(route('operational-advances.approve', $advance), ['approved_amount' => '1000.01'])
            ->assertSessionHasErrors('approved_amount');

        $this->assertSame('submitted', $advance->fresh()->status);
        $this->assertNull($advance->fresh()->approved_amount);
    }

    public function test_finance_can_request_revision_and_the_requester_can_resubmit(): void
    {
        $requester = $this->userWithRole('petugas_sarpras');
        $finance = $this->userWithRole('keuangan_sarpras');
        $cashAccount = CashAccount::factory()->create();

        $this->actingAs($requester)->post(route('operational-advances.store'), $this->advancePayload($cashAccount))->assertRedirect();
        $advance = OperationalAdvance::query()->where('requester_user_id', $requester->id)->firstOrFail();
        $this->post(route('operational-advances.submit', $advance))->assertRedirect();

        $this->actingAs($finance)->post(route('operational-advances.review', $advance), [
            'decision' => 'revision_requested', 'reason' => 'Mohon lengkapi tujuan kegiatan.',
        ])->assertRedirect();
        $this->assertSame('revision_requested', $advance->fresh()->status);

        $this->actingAs($requester)->put(route('operational-advances.update', $advance), $this->advancePayload($cashAccount, [
            'purpose' => 'Tujuan diperjelas.',
        ]))->assertRedirect();
        $this->post(route('operational-advances.submit', $advance))->assertRedirect();
        $this->assertSame('submitted', $advance->fresh()->status);
        $this->assertSame('Tujuan diperjelas.', $advance->fresh()->purpose);
    }

    public function test_finance_can_create_a_mandated_request_and_the_mandate_is_audited(): void
    {
        $finance = $this->userWithRole('keuangan_sarpras');
        $driver = $this->userWithRole('driver');
        $cashAccount = CashAccount::factory()->create();

        $this->actingAs($finance)->post(route('operational-advances.store'), $this->advancePayload($cashAccount, [
            'requester_user_id' => $driver->id,
            'responsible_user_id' => $driver->id,
            'mandate_reason' => 'Permintaan disampaikan melalui bagian Keuangan.',
        ]))->assertRedirect();

        $advance = OperationalAdvance::query()->where('requester_user_id', $driver->id)->firstOrFail();
        $this->assertSame($finance->id, $advance->created_by);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'operational_advance_created',
            'subject_id' => $advance->id,
        ]);
        $this->actingAs($driver)->get(route('operational-advances.show', $advance))->assertOk();
    }

    public function test_finance_cannot_approve_an_advance_they_requested_or_receive(): void
    {
        $finance = $this->userWithRole('keuangan_sarpras');
        $cashAccount = CashAccount::factory()->create();

        $this->actingAs($finance)->post(route('operational-advances.store'), $this->advancePayload($cashAccount))
            ->assertRedirect();
        $advance = OperationalAdvance::query()->where('requester_user_id', $finance->id)->firstOrFail();
        $this->post(route('operational-advances.submit', $advance))->assertRedirect();

        $this->post(route('operational-advances.approve', $advance), ['approved_amount' => '1000.00'])->assertForbidden();
        $this->assertSame('submitted', $advance->fresh()->status);
    }

    public function test_head_and_finance_division_can_read_but_cannot_manage_advances(): void
    {
        $owner = $this->userWithRole('driver');
        $advance = $this->advanceFor($owner);

        foreach (['kepala_sarpras', 'divisi_keuangan'] as $roleName) {
            $reader = $this->userWithRole($roleName);

            $this->actingAs($reader)->get(route('operational-advances.show', $advance))->assertOk();
            $this->post(route('operational-advances.cancel', $advance), ['reason' => 'Tidak berhak.'])->assertForbidden();
        }
    }

    public function test_admin_system_has_no_automatic_financial_advance_access(): void
    {
        $admin = $this->userWithRole('admin_system');
        $cashAccount = CashAccount::factory()->create();

        $this->actingAs($admin)->get(route('operational-advances.index'))->assertForbidden();
        $this->post(route('operational-advances.store'), $this->advancePayload($cashAccount))->assertForbidden();

        $this->assertFalse($admin->can('operational_advances.view'));
        $this->assertFalse($admin->can('operational_advances.view_own'));
    }

    public function test_advance_permission_matrix_matches_the_revised_design(): void
    {
        $petugas = $this->userWithRole('petugas_sarpras');
        $driver = $this->userWithRole('driver');
        $finance = $this->userWithRole('keuangan_sarpras');
        $head = $this->userWithRole('kepala_sarpras');
        $divisionFinance = $this->userWithRole('divisi_keuangan');
        $admin = $this->userWithRole('admin_system');

        foreach ([$petugas, $driver] as $requester) {
            $this->assertTrue($requester->can('operational_advances.view_own'));
            $this->assertTrue($requester->can('operational_advances.create'));
            $this->assertTrue($requester->can('operational_advances.submit'));
            $this->assertFalse($requester->can('operational_advances.view'));
            $this->assertFalse($requester->can('operational_advances.approve'));
        }

        foreach (['view', 'create', 'submit', 'review', 'approve', 'disburse', 'settle', 'return', 'reimburse', 'cancel', 'manage_all', 'report'] as $ability) {
            $this->assertTrue($finance->can('operational_advances.'.$ability));
        }

        foreach ([$head, $divisionFinance] as $reader) {
            $this->assertTrue($reader->can('operational_advances.view'));
            $this->assertTrue($reader->can('operational_advances.report'));
            $this->assertFalse($reader->can('operational_advances.create'));
            $this->assertFalse($reader->can('operational_advances.approve'));
        }

        foreach (['view', 'view_own', 'create', 'approve', 'disburse', 'report'] as $ability) {
            $this->assertFalse($admin->can('operational_advances.'.$ability));
        }
    }

    public function test_advance_request_attachment_is_private_and_scoped_to_its_bon(): void
    {
        Storage::fake('local');
        $owner = $this->userWithRole('driver');
        $other = $this->userWithRole('driver');
        $cashAccount = CashAccount::factory()->create();

        $this->actingAs($owner)->post(route('operational-advances.store'), $this->advancePayload($cashAccount, [
            'attachments' => [UploadedFile::fake()->createWithContent('request.pdf', "%PDF-1.4\nproof")],
        ]))->assertRedirect();

        $advance = OperationalAdvance::query()->where('requester_user_id', $owner->id)->firstOrFail();
        $attachment = $advance->attachments()->firstOrFail();
        Storage::disk('local')->assertExists($attachment->path);

        $this->get(route('operational-advances.attachments', ['operationalAdvance' => $advance, 'attachment' => $attachment]))->assertOk();
        $this->actingAs($other)->get(route('operational-advances.attachments', ['operationalAdvance' => $advance, 'attachment' => $attachment]))->assertNotFound();
    }

    public function test_multiple_roles_combine_permissions_without_bypassing_self_approval_rule(): void
    {
        $user = $this->userWithRole('driver');
        $user->roles()->attach(Role::query()->where('name', 'keuangan_sarpras')->firstOrFail());
        $cashAccount = CashAccount::factory()->create();
        $otherDriver = $this->userWithRole('driver');
        $otherAdvance = $this->advanceFor($otherDriver);

        $this->actingAs($user)->post(route('operational-advances.store'), $this->advancePayload($cashAccount))
            ->assertRedirect();
        $ownAdvance = OperationalAdvance::query()->where('requester_user_id', $user->id)->firstOrFail();
        $this->post(route('operational-advances.submit', $ownAdvance))->assertRedirect();

        $this->get(route('operational-advances.show', $otherAdvance))->assertOk();
        $this->post(route('operational-advances.approve', $ownAdvance), ['approved_amount' => '1000.00'])->assertForbidden();
        $this->assertTrue($user->can('operational_advances.view'));
        $this->assertTrue($user->can('operational_advances.approve'));
    }

    private function advancePayload(CashAccount $cashAccount, array $overrides = []): array
    {
        return array_merge([
            'purpose' => 'Kebutuhan operasional kegiatan.',
            'requested_amount' => '1000.00',
            'planned_cash_account_id' => $cashAccount->id,
            'due_date' => '2026-12-15',
        ], $overrides);
    }

    private function advanceFor(User $user): OperationalAdvance
    {
        return OperationalAdvance::factory()->create([
            'requester_user_id' => $user->id,
            'responsible_user_id' => $user->id,
            'created_by' => $user->id,
        ]);
    }

    private function userWithRole(string $roleName): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('name', $roleName)->firstOrFail());

        return $user;
    }
}
