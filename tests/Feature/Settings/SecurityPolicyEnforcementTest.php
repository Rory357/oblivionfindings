<?php

namespace Tests\Feature\Settings;

use App\Models\AppSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityPolicyEnforcementTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_policy_uses_saved_security_settings(): void
    {
        $this->setSecuritySetting('password_min_length', 16);
        $this->setSecuritySetting('password_require_uppercase', false);
        $this->setSecuritySetting('password_require_numbers', false);
        $this->setSecuritySetting('password_require_symbols', false);

        $this->post('/register', [
            'name' => 'Short Password',
            'email' => 'short-password@example.test',
            'password' => 'shortpassword',
            'password_confirmation' => 'shortpassword',
        ])->assertSessionHasErrors(['password']);

        $this->post('/register', [
            'name' => 'Long Password',
            'email' => 'long-password@example.test',
            'password' => 'sixteencharacters',
            'password_confirmation' => 'sixteencharacters',
        ])->assertRedirect(route('login', absolute: false));

        $this->assertDatabaseHas('users', [
            'email' => 'long-password@example.test',
            'role' => 'pending',
        ]);
        $this->assertGuest();
    }

    public function test_force_two_factor_redirects_users_without_confirmed_2fa(): void
    {
        $this->setSecuritySetting('force_2fa', true);
        $user = User::factory()->withoutTwoFactor()->create(['approved_at' => now()]);

        $this->actingAs($user)
            ->get('/settings/profile')
            ->assertRedirect('/settings/two-factor');

        $this->actingAs($user)
            ->get('/settings/two-factor')
            ->assertRedirect('/user/confirm-password');
    }

    public function test_force_two_factor_allows_users_with_confirmed_2fa(): void
    {
        $this->setSecuritySetting('force_2fa', true);
        $user = User::factory()->create(['approved_at' => now()]);

        $this->actingAs($user)
            ->get('/settings/profile')
            ->assertOk();
    }

    public function test_session_timeout_logs_out_idle_user(): void
    {
        $this->setSecuritySetting('session_timeout_minutes', 1);
        $user = User::factory()->create(['approved_at' => now()]);

        $this->actingAs($user)
            ->withSession(['last_activity_at' => now()->subMinutes(2)->timestamp])
            ->get('/settings/profile')
            ->assertRedirect(route('login', absolute: false))
            ->assertSessionHasErrors(['session']);

        $this->assertGuest();
    }

    public function test_session_timeout_records_activity_for_active_user(): void
    {
        $this->setSecuritySetting('session_timeout_minutes', 30);
        $user = User::factory()->create(['approved_at' => now()]);

        $this->actingAs($user)
            ->get('/settings/profile')
            ->assertOk();

        $this->assertGreaterThanOrEqual(now()->subSeconds(5)->timestamp, session('last_activity_at'));
    }

    private function setSecuritySetting(string $key, mixed $value): void
    {
        AppSetting::updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
