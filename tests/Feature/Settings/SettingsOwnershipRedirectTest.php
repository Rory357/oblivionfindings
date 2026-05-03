<?php

namespace Tests\Feature\Settings;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsOwnershipRedirectTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'approved_at' => now(),
        ]);
        $this->admin->roles()->attach(Role::where('name', 'admin')->firstOrFail());
    }

    public function test_settings_integrations_redirects_to_security_devices(): void
    {
        $this->actingAs($this->admin)
            ->get('/settings/integrations')
            ->assertStatus(301)
            ->assertRedirect('/security-devices/integrations');
    }

    public function test_settings_users_redirects_to_system_users(): void
    {
        $this->actingAs($this->admin)
            ->get('/settings/users')
            ->assertStatus(301)
            ->assertRedirect('/system/users');
    }

    public function test_settings_user_show_redirects_to_system_user_show(): void
    {
        $target = User::factory()->create(['approved_at' => now()]);

        $this->actingAs($this->admin)
            ->get("/settings/users/{$target->id}")
            ->assertStatus(301)
            ->assertRedirect("/system/users/{$target->id}");
    }

    public function test_identity_route_closures_have_controller_actions(): void
    {
        foreach (['settings.security', 'settings.security.update', 'settings.sso', 'auth.disconnect'] as $routeName) {
            $route = app('router')->getRoutes()->getByName($routeName);

            $this->assertNotNull($route, "Route [{$routeName}] should exist.");
            $this->assertIsString($route->getAction('controller'), "Route [{$routeName}] should use a controller action.");
        }
    }
}
