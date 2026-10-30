<?php

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Chat\ChatServiceHealth;
use Illuminate\Support\Str;

it('requires settings permission before checking services and keeps diagnostics private while rollout is disabled', function () {
    config(['chat.shared_experience' => false]);
    $health = Mockery::mock(ChatServiceHealth::class);
    $health->shouldReceive('snapshot')->once()->andReturn(['checked_at' => now()->toISOString(), 'rollout_enabled' => false, 'services' => []]);
    app()->instance(ChatServiceHealth::class, $health);
    $user = User::factory()->create(['approved_at' => now(), 'role' => 'staff']);
    $this->postJson(route('settings.chat.service-health'))->assertUnauthorized();
    $this->actingAs($user)->withHeader('X-Chat-Actor', (string) $user->id)->postJson(route('settings.chat.service-health'))->assertForbidden();
    $role = Role::create(['name' => 'chat_health_'.Str::random(8), 'label' => 'Chat settings', 'level' => 50, 'type' => 'custom']);
    $permission = Permission::firstOrCreate(['key' => 'settings.access.manage'], ['description' => 'Manage settings', 'group' => 'settings', 'module' => 'Settings']);
    $role->permissions()->attach($permission->id);
    $user->roles()->attach($role->id);
    $this->actingAs($user->fresh())->withHeader('X-Chat-Actor', (string) $user->id)->postJson(route('settings.chat.service-health'), ['url' => 'https://ignored.example.test', 'path' => '/private/ignored'])
        ->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('rollout_enabled', false);
    expect(config('chat.shared_experience'))->toBeFalse();
});
