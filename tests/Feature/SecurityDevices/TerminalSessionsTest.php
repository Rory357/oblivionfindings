<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Monitoring\Contracts\ApprovedProbeScopeProvider;
use App\Domain\Monitoring\Contracts\CredentialLeaseProvider;
use App\Domain\Monitoring\Contracts\DnsResolver;
use App\Domain\Monitoring\Data\CredentialLease;
use App\Domain\Monitoring\Discovery\Models\DiscoveryScope;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\Monitoring\Services\EgressPolicy;
use App\Domain\SecurityDevices\Credentials\Enums\CredentialReferenceStatus;
use App\Domain\SecurityDevices\Credentials\Enums\CredentialRotationStatus;
use App\Domain\SecurityDevices\Credentials\Enums\CredentialTestStatus;
use App\Domain\SecurityDevices\Credentials\Models\CredentialReference;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Terminal\Models\TerminalLine;
use App\Domain\SecurityDevices\Terminal\Models\TerminalSession;
use App\Domain\SecurityDevices\Terminal\TerminalSessions;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Support\Str;

/*
 * S&D Phase G (D5): a device's Terminal tab, through the hub, for IT admins only. Every line typed
 * is kept; it closes after 15 minutes without typing; the hub gets each "open" signed.
 */

const TERMINAL_SERVER_KEY = 'SHA256:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';

beforeEach(function (): void {
    $this->seed(RbacSeeder::class);
    $this->seed(SecurityDevicesPermissionsSeeder::class);
    $this->configPair = sodium_crypto_sign_seed_keypair(str_repeat("\x47", SODIUM_CRYPTO_SIGN_SEEDBYTES));
    config()->set('monitoring.collector.signing_secret_key', base64_encode(sodium_crypto_sign_secretkey($this->configPair)));

    $this->site = Site::factory()->create(['name' => 'Puriri House', 'is_active' => true, 'archived' => false, 'archived_at' => null]);
    DiscoveryScope::factory()->create([
        'site_id' => $this->site->id, 'collector_id' => null, 'cidrs' => ['10.77.0.0/24'], 'protocols' => ['tcp'],
        'port_bounds' => ['tcp' => [22]], 'exclusions' => [], 'status' => 'active',
    ]);
    $this->router = Device::factory()->create([
        'name' => 'Puriri House router', 'domain' => 'it_infrastructure', 'category' => 'network', 'subcategory' => 'router',
        'manufacturer' => 'MikroTik', 'model' => 'hAP ax3', 'provider' => 'mikrotik', 'ip_address' => '10.77.0.1', 'last_seen_at' => now(),
        'config' => ['ssh' => ['host_key_fingerprint' => TERMINAL_SERVER_KEY]],
    ]);
    DeviceAssignment::query()->create([
        'device_id' => $this->router->id, 'assignable_type' => DeviceAssignment::TARGET_SITE, 'assignable_id' => $this->site->id,
        'assignment_type' => 'permanent', 'assigned_at' => now()->subHour(),
    ]);
    CredentialReference::query()->create([
        'reference_key' => 'database:mikrotik/'.$this->site->id.'/shell', 'site_id' => $this->site->id, 'provider' => 'mikrotik',
        'purpose' => 'device_management', 'capabilities' => ['command:device.remote_shell'],
        'secret_manager_reference' => 'secret/data/sites/'.$this->site->id.'/mikrotik-shell',
        'secret_manager_reference_hash' => hash('sha256', 'terminal-shell-'.$this->site->id),
        'status' => CredentialReferenceStatus::Active, 'rotation_status' => CredentialRotationStatus::Current,
        'test_status' => CredentialTestStatus::Passed, 'version' => 1, 'last_tested_at' => now(),
    ]);
    $hubPair = sodium_crypto_sign_seed_keypair(str_repeat("\x48", SODIUM_CRYPTO_SIGN_SEEDBYTES));
    $hubPublic = sodium_crypto_sign_publickey($hubPair);
    $this->hub = MonitoringCollector::factory()->create([
        'site_id' => $this->site->id, 'name' => 'Puriri House', 'collector_uuid' => (string) Str::uuid(),
        'public_key' => base64_encode($hubPublic), 'public_key_fingerprint' => hash('sha256', $hubPublic),
        'client_certificate_fingerprint' => hash('sha256', 'terminal-hub-certificate'),
        'status' => 'online', 'last_seen_at' => now(), 'approved_at' => now()->subDay(), 'revoked_at' => null,
        'runtime_status' => ['version' => '2026.11.1', 'command_adapters' => 'ssh_config_backup_v1,terminal_v1'],
    ]);
    $this->app->instance(DnsResolver::class, new class implements DnsResolver
    {
        public function resolve(string $host): array
        {
            return ['10.77.0.1'];
        }
    });
    $this->app->instance(CredentialLeaseProvider::class, new class implements CredentialLeaseProvider
    {
        public function acquire(int $siteId, string $reference, array $capabilities): CredentialLease
        {
            return new CredentialLease('lease-'.Str::uuid(), now()->toImmutable()->addMinutes(5), ['username' => 'netadmin', 'password' => 'TERMINAL-TEST-ONLY-PASSWORD']);
        }
    });
    foreach ([ApprovedProbeScopeProvider::class, EgressPolicy::class, TerminalSessions::class] as $service) {
        $this->app->forgetInstance($service);
    }
    $this->person = function (array $keys): User {
        $user = User::factory()->create(['approved_at' => now()]);
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => $this->site->id, 'secondary_site_ids' => [], 'is_active' => true]);
        foreach ($keys as $key) {
            $user->permissionOverrides()->attach(Permission::query()->where('key', $key)->firstOrFail()->id, ['allowed' => true]);
        }

        return $user->fresh();
    };
    $this->admin = ($this->person)(['securityDevices.viewAny', 'securityDevices.devices.view', 'securityDevices.terminal.use']);
    $this->work = fn (array $report = []): array => app(TerminalSessions::class)->hubWork($this->hub->fresh(), $report);
});

it('opens only for IT admins with terminal.use, and never on another Site’s device', function (): void {
    $manager = ($this->person)(['securityDevices.viewAny', 'securityDevices.devices.view']);
    $this->actingAs($manager)->postJson("/security-devices/devices/{$this->router->id}/terminal")->assertForbidden();

    $other = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
    $farRouter = Device::factory()->create(['domain' => 'it_infrastructure', 'category' => 'network', 'subcategory' => 'router', 'ip_address' => '10.88.0.1']);
    DeviceAssignment::query()->create(['device_id' => $farRouter->id, 'assignable_type' => DeviceAssignment::TARGET_SITE, 'assignable_id' => $other->id, 'assignment_type' => 'permanent', 'assigned_at' => now()]);
    $this->actingAs($this->admin)->postJson("/security-devices/devices/{$farRouter->id}/terminal")->assertNotFound();

    $this->actingAs($this->admin)->postJson("/security-devices/devices/{$this->router->id}/terminal")->assertCreated()->assertJsonPath('state', 'requested');
    $this->assertDatabaseHas('audit_logs', ['action' => 'security_devices.terminal.opened']);
});

it('says why it can’t open: an older hub, an untrusted key or no command-line login', function (): void {
    $this->hub->forceFill(['runtime_status' => ['command_adapters' => 'ssh_config_backup_v1']])->save();
    $this->actingAs($this->admin)->postJson("/security-devices/devices/{$this->router->id}/terminal")
        ->assertUnprocessable()->assertJsonPath('errors.terminal.0', 'Puriri House hub needs its software update before it can open a terminal.');

    $this->hub->forceFill(['runtime_status' => ['command_adapters' => 'terminal_v1']])->save();
    $this->router->forceFill(['config' => []])->save();
    $this->actingAs($this->admin)->postJson("/security-devices/devices/{$this->router->id}/terminal")
        ->assertUnprocessable()->assertJsonPath('errors.terminal.0', 'Trust this device’s SSH key first: back up its settings once and choose Trust.');

    $this->router->forceFill(['config' => ['ssh' => ['host_key_fingerprint' => TERMINAL_SERVER_KEY]]])->save();
    CredentialReference::query()->update(['capabilities' => ['command:network.config.backup']]);
    $this->actingAs($this->admin)->postJson("/security-devices/devices/{$this->router->id}/terminal")
        ->assertUnprocessable()->assertJsonPath('errors.terminal.0', 'Save a command-line login for this device first.');
});

it('gives the hub a signed open with a sealed login, then carries lines both ways and keeps what was typed', function (): void {
    $uuid = $this->actingAs($this->admin)->postJson("/security-devices/devices/{$this->router->id}/terminal")->json('session');

    $work = ($this->work)();
    $open = $work['open'][0];
    expect($open['uuid'])->toBe($uuid)
        ->and($open['address'])->toBe('10.77.0.1')
        ->and($open['expected_host_key'])->toBe(TERMINAL_SERVER_KEY)
        ->and($open['credential_lease']['protocol'])->toBe('terminal.ssh')
        ->and($open['credential_lease']['target'])->toBe('10.77.0.1')
        ->and(sodium_crypto_sign_verify_detached(base64_decode($open['signature']), TerminalSessions::canonical($open), sodium_crypto_sign_publickey($this->configPair)))->toBeTrue()
        ->and(json_encode($work))->not->toContain('TERMINAL-TEST-ONLY-PASSWORD')
        ->and(TerminalSession::query()->sole()->state)->toBe('opening');

    ($this->work)(['sessions' => [['uuid' => $uuid, 'state' => 'open', 'login' => 'netadmin', 'out' => [['seq' => 1, 'text' => "[netadmin@router] > "]], 'in_ack' => 0]]]);
    expect(TerminalSession::query()->sole()->state)->toBe('open');

    $this->actingAs($this->admin)->postJson("/security-devices/terminal/{$uuid}/input", ['line' => '/system resource print'])->assertOk();
    $input = ($this->work)(['sessions' => [['uuid' => $uuid, 'state' => 'open', 'out' => [], 'in_ack' => 0]]])['input'];
    expect($input)->toBe([['uuid' => $uuid, 'lines' => [['seq' => 1, 'text' => '/system resource print']]]]);

    $this->actingAs($this->admin)->getJson("/security-devices/terminal/{$uuid}/output?after=0")
        ->assertOk()->assertJsonPath('state', 'open')->assertJsonPath('lines.0.text', '[netadmin@router] > ');
    expect(TerminalLine::query()->where('direction', 'in')->pluck('text')->all())->toBe(['/system resource print']);

    // Someone else can't read or type into it.
    $otherAdmin = ($this->person)(['securityDevices.viewAny', 'securityDevices.devices.view', 'securityDevices.terminal.use']);
    $this->actingAs($otherAdmin)->getJson("/security-devices/terminal/{$uuid}/output")->assertNotFound();
    $this->actingAs($otherAdmin)->postJson("/security-devices/terminal/{$uuid}/input", ['line' => 'reboot'])->assertNotFound();
});

it('closes after 15 minutes without typing, tells the hub, and drops what the device sent an hour later', function (): void {
    $uuid = $this->actingAs($this->admin)->postJson("/security-devices/devices/{$this->router->id}/terminal")->json('session');
    ($this->work)();
    ($this->work)(['sessions' => [['uuid' => $uuid, 'state' => 'open', 'out' => [['seq' => 1, 'text' => 'secret-ish settings']], 'in_ack' => 0]]]);
    $this->actingAs($this->admin)->postJson("/security-devices/terminal/{$uuid}/input", ['line' => 'export'])->assertOk();

    CarbonImmutable::setTestNow(now()->addMinutes(16));
    $work = ($this->work)(['sessions' => [['uuid' => $uuid, 'state' => 'open', 'out' => [], 'in_ack' => 1]]]);
    expect($work['close'])->toBe([$uuid])
        ->and(TerminalSession::query()->sole()->close_reason)->toBe('idle');

    CarbonImmutable::setTestNow(now()->addMinutes(61));
    app(TerminalSessions::class)->sweep();
    expect(TerminalLine::query()->where('direction', 'out')->count())->toBe(0)
        ->and(TerminalLine::query()->where('direction', 'in')->count())->toBe(1);
    CarbonImmutable::setTestNow();
});

it('refuses control characters in a typed line', function (): void {
    $uuid = $this->actingAs($this->admin)->postJson("/security-devices/devices/{$this->router->id}/terminal")->json('session');

    $this->actingAs($this->admin)->postJson("/security-devices/terminal/{$uuid}/input", ['line' => "show\nreboot"])->assertUnprocessable();
    $this->actingAs($this->admin)->postJson("/security-devices/terminal/{$uuid}/input", ['interrupt' => true])->assertOk();
    expect(TerminalLine::query()->where('direction', 'in')->pluck('text')->all())->toBe(["\x03"]);
});

it('sends the Terminal tab its state on the device page', function (): void {
    $this->actingAs($this->admin)->get("/security-devices/devices/{$this->router->id}?tab=terminal")
        ->assertOk()
        ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page->where('tab', 'terminal')->has('terminal'));
});
