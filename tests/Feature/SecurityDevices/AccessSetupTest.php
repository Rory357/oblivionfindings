<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\SecurityDevices\Access\AccessSetup;
use App\Domain\SecurityDevices\Access\Models\AccessConsole;
use App\Domain\SecurityDevices\Access\UnifiAccessSync;
use App\Domain\SecurityDevices\Cctv\Models\CameraEndpointTrust;
use App\Domain\SecurityDevices\Credentials\Models\CredentialReference;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Support\Str;

/*
 * S&D Phase H (pg-sdr-15m, pop-up 91): Setup › Doors & access. Connect a Site's console through
 * its hub, save its token into the secret store (never shown again), trust the certificate the hub
 * read, confirm every door opens from the inside. Another Site's console is 404.
 */

beforeEach(function (): void {
    $this->seed(RbacSeeder::class);
    $this->seed(SecurityDevicesPermissionsSeeder::class);
    config()->set('monitoring.credentials.driver', 'database');
    $this->site = Site::factory()->create(['name' => 'Miro House', 'is_active' => true, 'archived' => false, 'archived_at' => null]);
    $hubPublic = sodium_crypto_sign_publickey(sodium_crypto_sign_seed_keypair(str_repeat("\x57", SODIUM_CRYPTO_SIGN_SEEDBYTES)));
    $this->hub = MonitoringCollector::factory()->create([
        'site_id' => $this->site->id, 'name' => 'Miro House', 'collector_uuid' => (string) Str::uuid(),
        'public_key' => base64_encode($hubPublic), 'public_key_fingerprint' => hash('sha256', $hubPublic),
        'status' => 'online', 'last_seen_at' => now(), 'approved_at' => now()->subDay(), 'revoked_at' => null,
        'runtime_status' => ['version' => '2026.11.2', 'command_adapters' => 'unifi_access_v1,unifi_access_admin_v1,unifi_access_control_v1'],
    ]);
    $this->person = function (array $keys, ?Site $site = null): User {
        $user = User::factory()->create(['approved_at' => now()]);
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => ($site ?? $this->site)->id, 'secondary_site_ids' => [], 'is_active' => true]);
        foreach ($keys as $key) {
            $user->permissionOverrides()->attach(Permission::query()->where('key', $key)->firstOrFail()->id, ['allowed' => true]);
        }

        return $user->fresh();
    };
    $this->it = ($this->person)(['securityDevices.viewAny', 'securityDevices.integrations.view', 'securityDevices.integrations.manage', 'securityDevices.savedLogins.manage', 'securityDevices.commands.admin', 'securityDevices.commands.manage', 'securityDevices.accessControl.manage']);
});

it('shows Doors & access only to people who look after services or door access', function (): void {
    $this->actingAs($this->it)->get('/security-devices/setup/access')->assertOk()
        ->assertInertia(fn ($page) => $page->where('section', 'access')->where('access.places.0.site', 'Miro House')->where('access.places.0.console', null));

    $nobody = ($this->person)(['securityDevices.viewAny']);
    $this->actingAs($nobody)->get('/security-devices/setup/access')->assertForbidden();
});

it('connects a console at a private address at a Site with a hub, and never another Site’s', function (): void {
    $this->actingAs($this->it)->postJson('/security-devices/setup/access/consoles', ['site_id' => $this->site->id, 'address' => '8.8.8.8', 'port' => 12445])
        ->assertUnprocessable()->assertJsonValidationErrors('address');
    $this->actingAs($this->it)->postJson('/security-devices/setup/access/consoles', ['site_id' => $this->site->id, 'address' => '10.90.0.5', 'port' => 22])
        ->assertUnprocessable()->assertJsonValidationErrors('port');

    $other = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
    $this->actingAs($this->it)->postJson('/security-devices/setup/access/consoles', ['site_id' => $other->id, 'address' => '10.91.0.5', 'port' => 12445])->assertNotFound();

    $this->actingAs($this->it)->postJson('/security-devices/setup/access/consoles', ['site_id' => $this->site->id, 'address' => '10.90.0.5', 'port' => 12445])
        ->assertCreated()->assertJsonPath('console.state', 'waiting');
    $this->assertDatabaseHas('audit_logs', ['action' => 'security_devices.access.console_connected']);

    $this->hub->forceFill(['revoked_at' => now()])->save();
    $noHub = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
    $local = ($this->person)(['securityDevices.viewAny', 'securityDevices.integrations.manage'], $noHub);
    $this->actingAs($local)->postJson('/security-devices/setup/access/consoles', ['site_id' => $noHub->id, 'address' => '10.92.0.5', 'port' => 12445])
        ->assertUnprocessable()->assertJsonValidationErrors('site_id');
});

it('keeps the token in the secret store, never shows it, and needs both login rights', function (): void {
    $console = AccessConsole::query()->create(['site_id' => $this->site->id, 'address' => '10.90.0.5', 'port' => 12445, 'state' => 'waiting']);

    $manager = ($this->person)(['securityDevices.viewAny', 'securityDevices.integrations.manage', 'securityDevices.savedLogins.manage']);
    $this->actingAs($manager)->putJson("/security-devices/setup/access/consoles/{$console->id}/token", ['token' => 'TEST-ONLY-UNIFI-TOKEN-1'])->assertForbidden();

    $response = $this->actingAs($this->it)->putJson("/security-devices/setup/access/consoles/{$console->id}/token", ['token' => 'TEST-ONLY-UNIFI-TOKEN-1'])->assertOk();
    expect($response->getContent())->not->toContain('TEST-ONLY-UNIFI-TOKEN-1');

    $reference = UnifiAccessSync::reference($this->site->id);
    expect($reference)->not->toBeNull()
        ->and($reference->provider)->toBe('unifi_access')
        ->and($reference->capabilities)->toContain('read:access.sync')
        ->and($reference->capabilities)->toContain('command:access.site.lockdown')
        ->and(json_encode(CredentialReference::query()->get()->toArray()))->not->toContain('TEST-ONLY-UNIFI-TOKEN-1');

    $show = $this->actingAs($this->it)->getJson("/security-devices/setup/access/consoles/{$console->id}")->assertOk();
    expect($show->json('console.token.complete'))->toBeTrue()
        ->and($show->getContent())->not->toContain('TEST-ONLY-UNIFI-TOKEN-1');
});

it('trusts only the certificate the hub read, and lockdown waits for the exit-button tick', function (): void {
    $console = AccessConsole::query()->create(['site_id' => $this->site->id, 'address' => '10.90.0.5', 'port' => 12445, 'state' => 'untrusted']);
    CameraEndpointTrust::query()->create(['site_id' => $this->site->id, 'host' => '10.90.0.5', 'port' => 12445])->forceFill([
        'offered_pin' => 'sha256//MMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMM=', 'offered_fingerprint' => str_repeat('AB:', 31).'AB', 'offered_at' => now(),
    ])->save();

    $this->actingAs($this->it)->postJson("/security-devices/setup/access/consoles/{$console->id}/trust", ['fingerprint' => str_repeat('CD:', 31).'CD'])->assertUnprocessable();
    $this->actingAs($this->it)->postJson("/security-devices/setup/access/consoles/{$console->id}/trust", ['fingerprint' => str_repeat('AB:', 31).'AB'])->assertOk();
    expect(CameraEndpointTrust::query()->value('trusted_pin'))->toBe('sha256//MMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMM=');

    expect($console->fresh()->exit_buttons_confirmed_at)->toBeNull();
    $this->actingAs($this->it)->postJson("/security-devices/setup/access/consoles/{$console->id}/doors-confirmed")->assertOk();
    expect($console->fresh()->exit_buttons_confirmed_at)->not->toBeNull()
        ->and($console->fresh()->exit_buttons_confirmed_by_user_id)->toBe($this->it->id);
    $this->assertDatabaseHas('audit_logs', ['action' => 'security_devices.access.exit_buttons_confirmed']);

    // A different console at the Site hasn't had its doors checked.
    app(AccessSetup::class)->connect($this->it, $this->site->id, '10.90.0.6', 12445);
    expect($console->fresh()->exit_buttons_confirmed_at)->toBeNull();
});

it('asks for a reason before turning off the automatic leaver switch', function (): void {
    $this->actingAs($this->it)->patchJson('/security-devices/setup/settings', ['changes' => ['access.leavers_off' => false]])->assertUnprocessable()->assertJsonValidationErrors('reason');
    $this->actingAs($this->it)->patchJson('/security-devices/setup/settings', ['changes' => ['access.leavers_off' => false], 'reason' => 'Doing leavers by hand during the HR migration'])->assertSuccessful();
});
