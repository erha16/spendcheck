<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_log_in_with_valid_credentials(): void
    {
        $user = User::factory()->create(['password' => 'Secure password 123']);
        $initialSessionId = $this->app['session']->driver()->getId();
        $this->post(route('login'), ['email' => $user->email, 'password' => 'Secure password 123'])->assertRedirectToRoute('dashboard');
        $this->assertAuthenticatedAs($user);
        $this->assertNotSame($initialSessionId, $this->app['session']->driver()->getId());
        $this->assertDatabaseHas('audit_logs', ['action' => 'login', 'user_id' => $user->id]);
    }

    public function test_invalid_credentials_have_a_generic_error_and_are_audited(): void
    {
        $user = User::factory()->create(['password' => 'Secure password 123']);
        $this->from(route('login'))->post(route('login'), ['email' => $user->email, 'password' => 'incorrect'])->assertSessionHasErrors(['email' => 'Email atau kata sandi tidak cocok.']);
        $this->from(route('login'))->post(route('login'), ['email' => 'missing@example.test', 'password' => 'incorrect'])->assertSessionHasErrors(['email' => 'Email atau kata sandi tidak cocok.']);
        $this->assertDatabaseCount('audit_logs', 2);
        $this->assertGuest();
    }

    public function test_inactive_user_cannot_log_in(): void
    {
        $user = User::factory()->create(['password' => 'Secure password 123', 'is_active' => false]);
        $this->post(route('login'), ['email' => $user->email, 'password' => 'Secure password 123'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_logout_invalidates_the_session_and_records_activity(): void
    {
        $user = User::factory()->create();
        $initialToken = $this->app['session']->driver()->token();
        $this->actingAs($user)->post(route('logout'))->assertRedirectToRoute('login');
        $this->assertGuest();
        $this->assertNotSame($initialToken, $this->app['session']->driver()->token());
        $this->assertDatabaseHas('audit_logs', ['action' => 'logout', 'user_id' => $user->id]);
    }

    public function test_login_attempts_are_rate_limited(): void
    {
        foreach (range(1, 5) as $attempt) {
            $this->post(route('login'), ['email' => 'unknown@example.test', 'password' => 'incorrect']);
        }

        $this->post(route('login'), ['email' => 'unknown@example.test', 'password' => 'incorrect'])->assertTooManyRequests();
    }

    public function test_dashboard_requires_authentication(): void
    {
        $this->get(route('dashboard'))->assertRedirectToRoute('login');
    }

    public function test_inactive_authenticated_user_is_logged_out(): void
    {
        $user = User::factory()->create(['is_active' => false]);
        $this->actingAs($user)->get(route('dashboard'))->assertRedirectToRoute('login');
        $this->assertGuest();
    }

    public function test_failed_login_does_not_log_credentials(): void
    {
        $this->post(route('login'), ['email' => 'missing@example.test', 'password' => 'secret submitted value']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'login_failed']);
        $this->assertDatabaseMissing('audit_logs', ['new_values->password' => 'secret submitted value']);
    }
}
