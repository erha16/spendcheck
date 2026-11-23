<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\CashAccount;
use App\Models\Category;
use App\Models\FundSource;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Subcategory;
use App\Models\User;
use App\Models\Vendor;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MasterDataManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
    }

    public function test_user_without_master_permission_is_forbidden(): void
    {
        $this->actingAs(User::factory()->create());

        foreach (['categories', 'subcategories', 'activities', 'vendors', 'cash-accounts', 'fund-sources', 'payment-methods'] as $resource) {
            $this->get(route('admin.master.index', ['resource' => $resource]))->assertForbidden();
            $this->post(route('admin.master.store', ['resource' => $resource]), [])->assertForbidden();
        }
    }

    public function test_user_with_view_permission_cannot_create_master_data(): void
    {
        $role = Role::where('name', 'driver')->firstOrFail();
        $role->permissions()->attach(Permission::where('name', 'categories.view')->firstOrFail());
        $user = User::factory()->create();
        $user->roles()->attach($role);

        $this->actingAs($user)
            ->post(route('admin.master.store', ['resource' => 'categories']), ['code' => 'OPS', 'name' => 'Operasional'])
            ->assertForbidden();
    }

    public function test_administrator_can_render_each_master_list_and_form(): void
    {
        $this->actingAs($this->administrator());

        foreach (['categories', 'subcategories', 'activities', 'vendors', 'cash-accounts', 'fund-sources', 'payment-methods'] as $resource) {
            $this->get(route('admin.master.index', ['resource' => $resource]))->assertOk();
            $this->get(route('admin.master.create', ['resource' => $resource]))->assertOk();
        }
    }

    public function test_approved_role_matrix_controls_master_routes_and_mutations(): void
    {
        $masters = [
            'categories' => ['categories.view', 'categories.manage'],
            'subcategories' => ['subcategories.view', 'subcategories.manage'],
            'activities' => ['activities.view', 'activities.manage'],
            'vendors' => ['vendors.view', 'vendors.manage'],
            'cash-accounts' => ['cash_accounts.view', 'cash_accounts.manage'],
            'fund-sources' => ['fund_sources.view', 'fund_sources.manage'],
            'payment-methods' => ['payment_methods.view', 'payment_methods.manage'],
        ];
        $roleMatrix = [
            'admin_system' => array_merge(...array_values($masters)),
            'petugas_sarpras' => ['categories.view', 'subcategories.view', 'activities.view', 'vendors.view', 'payment_methods.view'],
            'driver' => ['activities.view'],
            'keuangan_sarpras' => array_merge(...array_values($masters)),
            'kepala_sarpras' => array_column($masters, 0),
            'divisi_keuangan' => array_column($masters, 0),
        ];

        foreach ($roleMatrix as $roleName => $expectedPermissions) {
            $user = User::factory()->create();
            $user->roles()->attach(Role::where('name', $roleName)->firstOrFail());
            $this->actingAs($user);

            foreach ($masters as $resource => [$viewPermission, $managePermission]) {
                $record = match ($resource) {
                    'categories' => Category::factory()->create(['name' => $roleName.' '.$resource]),
                    'subcategories' => Subcategory::factory()->create(['name' => $roleName.' '.$resource]),
                    'activities' => Activity::factory()->create(['name' => $roleName.' '.$resource]),
                    'vendors' => Vendor::factory()->create(['name' => $roleName.' '.$resource]),
                    'cash-accounts' => CashAccount::factory()->create(['name' => $roleName.' '.$resource]),
                    'fund-sources' => FundSource::factory()->create(['name' => $roleName.' '.$resource]),
                    'payment-methods' => PaymentMethod::factory()->create(['name' => $roleName.' '.$resource]),
                };
                $canView = in_array($viewPermission, $expectedPermissions, true) || in_array($managePermission, $expectedPermissions, true);
                $canManage = in_array($managePermission, $expectedPermissions, true);

                $this->get(route('admin.master.index', ['resource' => $resource]))->{$canView ? 'assertOk' : 'assertForbidden'}();
                $this->get(route('admin.master.show', ['resource' => $resource, 'record' => $record->id]))->{$canView ? 'assertOk' : 'assertForbidden'}();
                $createResponse = $this->get(route('admin.master.create', ['resource' => $resource]));
                $editResponse = $this->get(route('admin.master.edit', ['resource' => $resource, 'record' => $record->id]));
                $this->{$canManage ? 'assertTrue' : 'assertFalse'}($createResponse->status() === 200);
                $this->{$canManage ? 'assertTrue' : 'assertFalse'}($editResponse->status() === 200);

                $payload = $this->payloadFor($resource, $roleName.' updated '.$resource);
                $update = $this->put(route('admin.master.update', ['resource' => $resource, 'record' => $record->id]), $payload);
                $this->{$canManage ? 'assertTrue' : 'assertFalse'}($update->status() === 302);
                if (! $canManage) {
                    $update->assertForbidden();
                    $this->patch(route('admin.master.status.update', ['resource' => $resource, 'record' => $record->id]), ['is_active' => false])->assertForbidden();
                } else {
                    $this->patch(route('admin.master.status.update', ['resource' => $resource, 'record' => $record->id]), ['is_active' => false])->assertRedirect();
                }
            }
        }
    }

    public function test_multiple_roles_combine_permissions_and_inactive_users_are_denied(): void
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::whereIn('name', ['driver', 'petugas_sarpras'])->get());
        $this->actingAs($user)->get(route('admin.master.index', ['resource' => 'activities']))->assertOk();
        $this->get(route('admin.master.index', ['resource' => 'vendors']))->assertOk();
        $this->get(route('admin.master.index', ['resource' => 'fund-sources']))->assertForbidden();

        $inactive = User::factory()->create(['is_active' => false]);
        $inactive->roles()->attach(Role::where('name', 'admin_system')->firstOrFail());
        $this->actingAs($inactive)->get(route('admin.master.index', ['resource' => 'categories']))->assertRedirectToRoute('login');
    }

    public function test_access_control_seeder_is_idempotent_and_preserves_other_module_permissions(): void
    {
        $driverRole = Role::where('name', 'driver')->firstOrFail();
        $driverRole->permissions()->attach(Permission::where('name', 'users.view')->firstOrFail());
        $this->seed(AccessControlSeeder::class);
        $first = Role::where('name', 'driver')->firstOrFail()->permissions()->pluck('permissions.name')->sort()->values()->all();
        $this->seed(AccessControlSeeder::class);
        $second = Role::where('name', 'driver')->firstOrFail()->permissions()->pluck('permissions.name')->sort()->values()->all();

        $this->assertSame($first, $second);
        $this->assertContains('users.view', $second);
        $this->assertSame(['activities.view'], array_values(array_filter($second, fn (string $permission): bool => str_starts_with($permission, 'activities.') || str_starts_with($permission, 'categories.') || str_starts_with($permission, 'subcategories.') || str_starts_with($permission, 'vendors.') || str_starts_with($permission, 'cash_accounts.') || str_starts_with($permission, 'fund_sources.') || str_starts_with($permission, 'payment_methods.'))));
    }

    private function payloadFor(string $resource, string $name): array
    {
        return match ($resource) {
            'categories' => ['code' => 'UP-'.str_replace('-', '', $name), 'name' => $name],
            'subcategories' => ['category_id' => Category::factory()->create()->id, 'code' => 'UP-'.str_replace('-', '', $name), 'name' => $name],
            'activities' => ['code' => 'UP-'.str_replace('-', '', $name), 'name' => $name],
            'vendors' => ['name' => $name, 'type' => 'Vendor'],
            'cash-accounts' => ['name' => $name, 'type' => 'tunai'],
            'fund-sources', 'payment-methods' => ['name' => $name],
        };
    }

    public function test_administrator_can_create_and_search_master_data(): void
    {
        $admin = $this->administrator();

        $this->actingAs($admin)
            ->post(route('admin.master.store', ['resource' => 'categories']), ['code' => 'OPS', 'name' => 'Operasional', 'description' => 'Belanja rutin'])
            ->assertRedirect(route('admin.master.index', ['resource' => 'categories']));

        $this->assertDatabaseHas('categories', ['code' => 'OPS', 'name' => 'Operasional', 'is_active' => true]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'category_created', 'module' => 'categories']);
        $this->get(route('admin.master.index', ['resource' => 'categories', 'search' => 'Operasional']))->assertOk()->assertSee('Operasional');
    }

    public function test_duplicate_category_code_and_name_are_rejected(): void
    {
        $this->actingAs($this->administrator())
            ->post(route('admin.master.store', ['resource' => 'categories']), ['code' => 'OPS', 'name' => 'Operasional'])
            ->assertRedirect();

        $this->post(route('admin.master.store', ['resource' => 'categories']), ['code' => 'OPS', 'name' => 'Lainnya'])->assertSessionHasErrors('code');
        $this->post(route('admin.master.store', ['resource' => 'categories']), ['code' => 'OTHER', 'name' => 'Operasional'])->assertSessionHasErrors('name');
    }

    public function test_subcategory_is_linked_to_category_and_prevents_category_deletion(): void
    {
        $admin = $this->administrator();
        $category = Category::create(['code' => 'OPS', 'name' => 'Operasional', 'is_active' => true]);

        $this->actingAs($admin)
            ->post(route('admin.master.store', ['resource' => 'subcategories']), ['category_id' => $category->id, 'code' => 'FUEL', 'name' => 'Bahan bakar'])
            ->assertRedirect();

        $subcategory = Subcategory::where('code', 'FUEL')->firstOrFail();
        $this->assertSame($category->id, $subcategory->category_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'subcategory_created', 'subject_id' => $subcategory->id]);
        $this->delete(route('admin.master.destroy', ['resource' => 'categories', 'record' => $category->id]))->assertSessionHasErrors('record');
        $this->assertModelExists($category);
    }

    public function test_activity_dates_and_all_other_master_types_can_be_created(): void
    {
        $this->actingAs($this->administrator());

        $this->post(route('admin.master.store', ['resource' => 'activities']), ['code' => 'MEET', 'name' => 'Rapat tahunan', 'starts_at' => '2026-12-05', 'ends_at' => '2026-12-04'])->assertSessionHasErrors('ends_at');
        $this->post(route('admin.master.store', ['resource' => 'activities']), ['code' => 'MEET', 'name' => 'Rapat tahunan', 'starts_at' => '2026-12-04', 'ends_at' => '2026-12-05'])->assertRedirect();
        $this->post(route('admin.master.store', ['resource' => 'vendors']), ['name' => 'Vendor A', 'type' => 'Perusahaan', 'phone' => '081234'])->assertRedirect();
        $this->post(route('admin.master.store', ['resource' => 'cash-accounts']), ['name' => 'Kas operasional', 'type' => 'tunai', 'balance' => 100000])->assertRedirect();
        $this->post(route('admin.master.store', ['resource' => 'cash-accounts']), ['name' => 'Bank operasional', 'type' => 'bank', 'account_number' => '123', 'bank_name' => 'Bank Contoh'])->assertRedirect();
        $this->post(route('admin.master.store', ['resource' => 'fund-sources']), ['name' => 'APBD', 'description' => 'Anggaran daerah'])->assertRedirect();
        $this->post(route('admin.master.store', ['resource' => 'payment-methods']), ['name' => 'QRIS'])->assertRedirect();

        $this->assertDatabaseHas('vendors', ['name' => 'Vendor A', 'type' => 'Perusahaan']);
        $this->assertDatabaseHas('cash_accounts', ['name' => 'Kas operasional', 'type' => 'tunai']);
        $this->assertDatabaseHas('cash_accounts', ['name' => 'Bank operasional', 'type' => 'bank']);
        $this->assertDatabaseHas('fund_sources', ['name' => 'APBD']);
        $this->assertDatabaseHas('payment_methods', ['name' => 'QRIS']);
        $this->assertDatabaseMissing('cash_accounts', ['balance' => 100000]);
    }

    public function test_each_master_supports_crud_status_and_auditing(): void
    {
        $category = Category::factory()->create();
        $resources = [
            ['resource' => 'categories', 'model' => Category::class, 'action' => 'category', 'data' => ['code' => 'CAT-AUDIT', 'name' => 'Kategori audit', 'description' => 'Awal']],
            ['resource' => 'subcategories', 'model' => Subcategory::class, 'action' => 'subcategory', 'data' => ['category_id' => $category->id, 'code' => 'SUB-AUDIT', 'name' => 'Subkategori audit']],
            ['resource' => 'activities', 'model' => Activity::class, 'action' => 'activity', 'data' => ['code' => 'ACT-AUDIT', 'name' => 'Kegiatan audit', 'starts_at' => '2026-12-01', 'ends_at' => '2026-12-02']],
            ['resource' => 'vendors', 'model' => Vendor::class, 'action' => 'vendor', 'data' => ['name' => 'Vendor audit', 'type' => 'Perusahaan']],
            ['resource' => 'cash-accounts', 'model' => CashAccount::class, 'action' => 'cash_account', 'data' => ['name' => 'Kas audit', 'type' => 'tunai']],
            ['resource' => 'fund-sources', 'model' => FundSource::class, 'action' => 'fund_source', 'data' => ['name' => 'Dana audit']],
            ['resource' => 'payment-methods', 'model' => PaymentMethod::class, 'action' => 'payment_method', 'data' => ['name' => 'Metode audit']],
        ];

        $this->actingAs($this->administrator());

        foreach ($resources as $configuration) {
            $resource = $configuration['resource'];
            $model = $configuration['model'];
            $data = $configuration['data'];
            $originalName = $data['name'];

            $this->post(route('admin.master.store', ['resource' => $resource]), $data)->assertRedirect();
            $record = $model::query()->where('name', $originalName)->firstOrFail();
            $this->assertDatabaseHas('audit_logs', ['action' => $configuration['action'].'_created', 'subject_id' => $record->id]);

            $updatedData = [...$data, 'name' => $originalName.' updated'];
            $this->put(route('admin.master.update', ['resource' => $resource, 'record' => $record->id]), $updatedData)->assertRedirect();
            $this->assertDatabaseHas('audit_logs', ['action' => $configuration['action'].'_updated', 'subject_id' => $record->id]);

            $this->patch(route('admin.master.status.update', ['resource' => $resource, 'record' => $record->id]), ['is_active' => false])->assertRedirect();
            $this->assertDatabaseHas($record->getTable(), ['id' => $record->id, 'is_active' => false]);
            $this->assertDatabaseHas('audit_logs', ['action' => $configuration['action'].'_status_changed', 'subject_id' => $record->id]);

            $this->delete(route('admin.master.destroy', ['resource' => $resource, 'record' => $record->id]))->assertRedirect();
            $this->assertDatabaseHas('audit_logs', ['action' => $configuration['action'].'_deleted', 'subject_id' => $record->id]);
            $this->assertModelMissing($record);
        }
    }

    public function test_invalid_master_relationship_and_cash_account_type_are_rejected(): void
    {
        $this->actingAs($this->administrator());

        $this->post(route('admin.master.store', ['resource' => 'subcategories']), ['category_id' => 999999, 'code' => 'SUB-BAD', 'name' => 'Subkategori tanpa kategori'])->assertSessionHasErrors('category_id');
        $this->post(route('admin.master.store', ['resource' => 'cash-accounts']), ['name' => 'Akun tidak valid', 'type' => 'wallet'])->assertSessionHasErrors('type');
    }

    public function test_master_search_results_are_paginated(): void
    {
        $this->actingAs($this->administrator());

        for ($number = 1; $number <= 16; $number++) {
            Category::factory()->create(['name' => 'Paged Category '.str_pad((string) $number, 2, '0', STR_PAD_LEFT)]);
        }

        $this->get(route('admin.master.index', ['resource' => 'categories', 'page' => 2]))
            ->assertOk()
            ->assertSee('Paged Category 16')
            ->assertDontSee('Paged Category 01');
    }

    public function test_master_updates_status_and_deletion_are_audited(): void
    {
        $this->actingAs($this->administrator())
            ->post(route('admin.master.store', ['resource' => 'fund-sources']), ['name' => 'APBD'])
            ->assertRedirect();
        $fundSource = FundSource::firstOrFail();

        $this->put(route('admin.master.update', ['resource' => 'fund-sources', 'record' => $fundSource->id]), ['name' => 'APBD pusat', 'description' => 'Pusat'])->assertRedirect();
        $this->patch(route('admin.master.status.update', ['resource' => 'fund-sources', 'record' => $fundSource->id]), ['is_active' => false])->assertRedirect();
        $this->assertDatabaseHas('audit_logs', ['action' => 'fund_source_updated', 'subject_id' => $fundSource->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'fund_source_status_changed', 'subject_id' => $fundSource->id]);

        $this->delete(route('admin.master.destroy', ['resource' => 'fund-sources', 'record' => $fundSource->id]))->assertRedirect();
        $this->assertDatabaseHas('audit_logs', ['action' => 'fund_source_deleted', 'subject_id' => $fundSource->id]);
        $this->assertModelMissing($fundSource);
    }

    private function administrator(): User
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::where('name', 'admin_system')->firstOrFail());

        return $admin;
    }
}
