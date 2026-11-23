<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InitialAdministratorCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_initial_administrator_is_created_from_hidden_password_prompts(): void
    {
        $this->seed(AccessControlSeeder::class);
        $password = 'A secure administrator 123';

        $this->artisan('app:create-initial-administrator')
            ->expectsQuestion('Nama administrator', 'System Admin')
            ->expectsQuestion('Email administrator', 'system-admin@example.test')
            ->expectsQuestion('Kata sandi (minimal 12 karakter)', $password)
            ->expectsQuestion('Ulangi kata sandi', $password)
            ->assertExitCode(0);

        $user = User::where('email', 'system-admin@example.test')->firstOrFail();
        $this->assertTrue(password_verify($password, $user->password));
        $this->assertTrue($user->hasRole('admin_system'));
    }

    public function test_seeder_never_creates_an_administrator_implicitly(): void
    {
        $this->seed(AccessControlSeeder::class);

        $this->assertDatabaseCount('users', 0);
        $this->assertInstanceOf(Role::class, Role::where('name', 'admin_system')->firstOrFail());
    }
}
