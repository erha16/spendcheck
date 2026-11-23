<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
    }

    public function test_user_without_permission_receives_forbidden_response(): void
    {
        $this->actingAs(User::factory()->create())->get(route('admin.users.index'))->assertForbidden();
    }

    public function test_user_with_permission_can_view_users(): void
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('name', 'admin_system')->firstOrFail());
        $this->actingAs($user)->get(route('admin.users.index'))->assertOk();
    }

    public function test_user_can_have_multiple_roles(): void
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::whereIn('name', ['driver', 'petugas_sarpras'])->get());
        $this->assertCount(2, $user->fresh()->roles);
    }

    public function test_administrator_can_create_a_user_with_a_hashed_password(): void
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::where('name', 'admin_system')->firstOrFail());
        $this->actingAs($admin)->post(route('admin.users.store'), ['name' => 'New Employee', 'email' => 'new@example.test', 'password' => 'A strong pass 123', 'password_confirmation' => 'A strong pass 123'])
            ->assertRedirect();
        $created = User::where('email', 'new@example.test')->firstOrFail();
        $this->assertTrue(password_verify('A strong pass 123', $created->password));
        $this->assertDatabaseHas('audit_logs', ['action' => 'user_created']);
    }

    public function test_duplicate_email_is_rejected(): void
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::where('name', 'admin_system')->firstOrFail());
        $existing = User::factory()->create();
        $this->actingAs($admin)->post(route('admin.users.store'), ['name' => 'Duplicate', 'email' => $existing->email, 'password' => 'A strong pass 123', 'password_confirmation' => 'A strong pass 123'])->assertSessionHasErrors('email');
    }

    public function test_user_cannot_deactivate_their_own_account(): void
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::where('name', 'admin_system')->firstOrFail());
        $this->actingAs($admin)->patch(route('admin.users.status.update', $admin), ['is_active' => false])->assertStatus(422);
        $this->assertTrue($admin->fresh()->is_active);
    }

    public function test_last_active_administrator_cannot_be_deactivated_or_unassigned(): void
    {
        $admin = User::factory()->create();
        $adminRole = Role::where('name', 'admin_system')->firstOrFail();
        $admin->roles()->attach($adminRole);
        $operator = User::factory()->create();
        $operatorRole = Role::where('name', 'driver')->firstOrFail();
        $operatorRole->permissions()->attach($adminRole->permissions()->whereIn('permissions.name', ['users.deactivate', 'roles.assign'])->get());
        $operator->roles()->attach($operatorRole);

        $this->actingAs($operator)->patch(route('admin.users.status.update', $admin), ['is_active' => false])->assertStatus(422);
        $this->patch(route('admin.users.roles.update', $admin), ['role_ids' => []])->assertStatus(422);
        $this->assertTrue($admin->fresh()->hasRole('admin_system'));
    }

    public function test_role_changes_are_audited(): void
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::where('name', 'admin_system')->firstOrFail());
        $target = User::factory()->create();
        $driver = Role::where('name', 'driver')->firstOrFail();
        $this->actingAs($admin)->patch(route('admin.users.roles.update', $target), ['role_ids' => [$driver->id]])->assertRedirect();
        $this->assertTrue($target->fresh()->hasRole('driver'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'roles_changed', 'subject_id' => $target->id]);
    }

    public function test_authorized_user_can_view_user_detail_and_management_pages(): void
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::where('name', 'admin_system')->firstOrFail());
        $target = User::factory()->create();

        $this->actingAs($admin)->get(route('admin.users.show', $target))->assertOk();
        $this->get(route('admin.users.create'))->assertOk();
        $this->get(route('admin.departments.index'))->assertOk();
        $this->get(route('admin.departments.create'))->assertOk();
        $this->get(route('admin.audit-logs.index'))->assertOk();
    }

    public function test_department_can_be_created_and_audited(): void
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::where('name', 'admin_system')->firstOrFail());

        $this->actingAs($admin)->post(route('admin.departments.store'), ['code' => 'OPS', 'name' => 'Operasional', 'description' => 'Unit operasional'])
            ->assertRedirect(route('admin.departments.index'));

        $this->assertDatabaseHas('departments', ['code' => 'OPS', 'is_active' => true]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'department_created']);
    }

    public function test_admin_password_reset_hashes_password_and_does_not_audit_it(): void
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::where('name', 'admin_system')->firstOrFail());
        $target = User::factory()->create();

        $this->actingAs($admin)->put(route('admin.users.password.update', $target), ['password' => 'New secure password 123', 'password_confirmation' => 'New secure password 123'])->assertRedirect();

        $this->assertTrue(password_verify('New secure password 123', $target->fresh()->password));
        $this->assertDatabaseHas('audit_logs', ['action' => 'password_reset']);
        $this->assertDatabaseMissing('audit_logs', ['new_values->password' => 'New secure password 123']);
    }
}
