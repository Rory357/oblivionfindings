<?php

use App\Domain\SecurityDevices\Endpoints\Services\EndpointRemoteSupportSettings;
use App\Models\Permission;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;

/*
 * S&D Phase G (pg-sdr-15k): Setup › Computers & Obli › Remote support. "Allow remote support" is
 * today's setting (the same one Settings › Obli changes), only for people who may change it there,
 * and audited there. Allow at the computer and "never recorded" are facts, not switches.
 */

beforeEach(function (): void {
    $this->seed(RbacSeeder::class);
    $this->seed(SecurityDevicesPermissionsSeeder::class);
    $this->person = function (array $keys): User {
        $user = User::factory()->create(['approved_at' => now(), 'email_verified_at' => now()]);
        foreach ($keys as $key) {
            $user->permissionOverrides()->attach(Permission::query()->where('key', $key)->firstOrFail()->id, ['allowed' => true]);
        }

        return $user->fresh();
    };
});

it('turns remote support on and off through today’s setting, audited', function (): void {
    $admin = ($this->person)(['securityDevices.viewAny', 'settings.access.manage', 'integrations.manage_secrets']);

    $this->actingAs($admin)->get('/security-devices/setup/computers')->assertOk()
        ->assertInertia(fn ($page) => $page->where('section', 'computers')->where('remote.allowed', false)->where('remote.can_change', true));

    $this->actingAs($admin)->putJson('/security-devices/setup/computers/remote-support', ['allowed' => true])->assertOk();
    expect(app(EndpointRemoteSupportSettings::class)->current()['remote_support_enabled'])->toBeTrue();
    $this->assertDatabaseHas('audit_logs', ['action' => 'endpoint.remote_support.settings_changed']);

    $this->actingAs($admin)->putJson('/security-devices/setup/computers/remote-support', ['allowed' => false])->assertOk();
    expect(app(EndpointRemoteSupportSettings::class)->current()['remote_support_enabled'])->toBeFalse();
});

it('shows the tab to people who look after remote support, but only lets settings admins change it', function (): void {
    $policy = ($this->person)(['securityDevices.viewAny', 'securityDevices.remote.managePolicy']);
    $this->actingAs($policy)->get('/security-devices/setup/computers')->assertOk()
        ->assertInertia(fn ($page) => $page->where('remote.can_change', false));
    $this->actingAs($policy)->putJson('/security-devices/setup/computers/remote-support', ['allowed' => true])->assertForbidden();
    expect(app(EndpointRemoteSupportSettings::class)->current()['remote_support_enabled'])->toBeFalse();

    $nobody = ($this->person)(['securityDevices.viewAny']);
    $this->actingAs($nobody)->putJson('/security-devices/setup/computers/remote-support', ['allowed' => true])->assertForbidden();
});
