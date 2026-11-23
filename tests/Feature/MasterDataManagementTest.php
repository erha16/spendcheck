<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\FundSource;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Subcategory;
use App\Models\User;
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
        $this->actingAs(User::factory()->create())
            ->get(route('admin.master.index', ['resource' => 'categories']))
            ->assertForbidden();
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
