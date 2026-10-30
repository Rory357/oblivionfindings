<?php

use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Chat\ChatServiceHealth;
use App\Services\Chat\ChatSettings;
use Illuminate\Support\Str;

it('binds settings review writes and diagnostics to the rendered administrator while rollout is disabled', function () {
    config(['chat.shared_experience' => false, 'chat.calling.url' => '', 'chat.calling.api_key' => '', 'chat.calling.api_secret' => '']);
    $first = User::factory()->create(['role' => 'staff', 'approved_at' => now()]);
    $second = User::factory()->create(['role' => 'staff', 'approved_at' => now()]);
    $role = Role::create(['name' => 'chat_admin_actor_'.Str::random(8), 'label' => 'Chat settings', 'level' => 50, 'type' => 'custom']);
    $permission = Permission::firstOrCreate(['key' => 'settings.access.manage'], ['description' => 'Manage settings', 'group' => 'settings', 'module' => 'Settings']);
    $role->permissions()->attach($permission->id);
    foreach ([$first, $second] as $person) {
        $person->roles()->attach($role->id);
    }
    $health = Mockery::mock(ChatServiceHealth::class);
    $health->shouldReceive('snapshot')->once()->andReturn(['checked_at' => now()->toISOString(), 'rollout_enabled' => false, 'services' => []]);
    app()->instance(ChatServiceHealth::class, $health);
    $before = app(ChatSettings::class)->read();
    $payload = [...$before, 'max_files' => 4];
    $audits = AuditLog::where('action', 'chat.settings.updated')->count();
    $requests = [
        ['GET', route('settings.chat.configuration'), []],
        ['PUT', route('settings.chat.update'), $payload],
        ['POST', route('settings.chat.service-health'), []],
        ['POST', route('settings.chat.calling-health'), []],
    ];
    $this->actingAs($second->fresh());
    foreach ([null, (string) $first->id] as $actor) {
        if ($actor !== null) {
            $this->withHeader('X-Chat-Actor', $actor);
        }
        foreach ($requests as [$method, $url, $body]) {
            $this->json($method, $url, $body)->assertForbidden()->assertJsonPath('code', 'chat_actor_changed')
                ->assertHeader('Cache-Control', 'no-store, private');
        }
    }
    expect(app(ChatSettings::class)->read())->toBe($before);
    expect(AuditLog::where('action', 'chat.settings.updated')->count())->toBe($audits);
    $this->withHeader('X-Chat-Actor', (string) $second->id);
    $this->getJson(route('settings.chat.configuration'))->assertOk()->assertJsonPath('settings.version', $before['version']);
    $this->putJson(route('settings.chat.update'), $payload)->assertOk()->assertJsonPath('settings.max_files', 4);
    expect(AuditLog::where('action', 'chat.settings.updated')->latest('id')->first()->user_id)->toBe($second->id);
    $this->postJson(route('settings.chat.service-health'))->assertOk()->assertJsonPath('rollout_enabled', false);
    $this->postJson(route('settings.chat.calling-health'))->assertOk()->assertJsonPath('configured', false);
    $second->roles()->detach();
    $this->actingAs($second->fresh())->getJson(route('settings.chat.configuration'))->assertForbidden();
    $this->putJson(route('settings.chat.update'), $payload)->assertForbidden();
    expect(config('chat.shared_experience'))->toBeFalse();
});
