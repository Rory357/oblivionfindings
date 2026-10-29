<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Monitoring\Contracts\ApprovedProbeScopeProvider;
use App\Domain\Monitoring\Contracts\CredentialLeaseProvider;
use App\Domain\Monitoring\Contracts\DnsResolver;
use App\Domain\Monitoring\Contracts\SnapshotStore;
use App\Domain\Monitoring\Data\AuthorizedProbeTarget;
use App\Domain\Monitoring\Data\CredentialLease;
use App\Domain\Monitoring\Discovery\Models\DiscoveryScope;
use App\Domain\Monitoring\Models\ConfigurationSnapshot;
use App\Domain\Monitoring\Services\ConfigurationSnapshotService;
use App\Domain\Monitoring\Services\EgressPolicy;
use App\Domain\SecurityDevices\Credentials\Enums\CredentialReferenceStatus;
use App\Domain\SecurityDevices\Credentials\Enums\CredentialRotationStatus;
use App\Domain\SecurityDevices\Credentials\Enums\CredentialTestStatus;
use App\Domain\SecurityDevices\Credentials\Models\CredentialReference;
use App\Domain\SecurityDevices\Credentials\Services\CommandCredentialLeaseService;
use App\Domain\SecurityDevices\Management\Adapters\NetworkSshConfigBackupCommandAdapter;
use App\Domain\SecurityDevices\Management\Data\CommandRequestInput;
use App\Domain\SecurityDevices\Management\Enums\CommandStatus;
use App\Domain\SecurityDevices\Management\Services\CommandExecutionAdapterRegistry;
use App\Domain\SecurityDevices\Management\Services\DeviceCommandReconciliationService;
use App\Domain\SecurityDevices\Management\Services\DeviceCommandRequestService;
use App\Domain\SecurityDevices\Management\Services\GovernedCommandDispatchService;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\NetworkConfig\Contracts\NetworkCliSshTransport;
use App\Domain\SecurityDevices\NetworkConfig\Data\NetworkCliCommandResult;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Phase A, item A6 (docs/security-devices/GOAL.md): the SSH backup sends the saved login only to a
 * device whose key someone has trusted, and refuses a changed key until an IT admin replaces it.
 *
 * The stand-in transport follows the same rule as PhpseclibNetworkCliTransport: no trusted key, no
 * login; a different key, refused. It records whether the login would have been sent.
 */

const SSH_KEY_ONE = 'SHA256:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';
const SSH_KEY_TWO = 'SHA256:BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB';

final class PinnedSshDeviceStandIn implements NetworkCliSshTransport
{
    public string $presentedKey = SSH_KEY_ONE;

    /** @var list<array{expected: string|null, login_sent: bool}> */
    public array $calls = [];

    public function runReadOnlyExport(AuthorizedProbeTarget $target, array $credentialMaterial, string $command, ?string $expectedHostKeyFingerprint = null, int $timeoutSeconds = 15): NetworkCliCommandResult
    {
        if ($expectedHostKeyFingerprint === null || trim($expectedHostKeyFingerprint) === '') {
            $this->calls[] = ['expected' => null, 'login_sent' => false];

            return new NetworkCliCommandResult(ok: false, reasonCode: 'host_key_unconfirmed', output: '', hostKeyFingerprint: $this->presentedKey);
        }
        if ($expectedHostKeyFingerprint !== $this->presentedKey) {
            $this->calls[] = ['expected' => $expectedHostKeyFingerprint, 'login_sent' => false];

            return new NetworkCliCommandResult(ok: false, reasonCode: 'host_key_mismatch', output: '', hostKeyFingerprint: $this->presentedKey, hostKeyPinned: true);
        }

        $this->calls[] = ['expected' => $expectedHostKeyFingerprint, 'login_sent' => true];

        return new NetworkCliCommandResult(ok: true, reasonCode: 'exported', output: "/interface bridge add name=br-lan\n/ip address add address=10.77.0.1/24", hostKeyFingerprint: $this->presentedKey, hostKeyPinned: true);
    }
}

beforeEach(function (): void {
    $this->seed(RbacSeeder::class);
    $this->seed(SecurityDevicesPermissionsSeeder::class);
    config()->set('monitoring.signing.active_key_id', 'ssh-trust-test-key');
    config()->set('monitoring.signing.keys', ['ssh-trust-test-key' => base64_encode(str_repeat('S', SODIUM_CRYPTO_AUTH_KEYBYTES))]);

    $this->site = Site::factory()->create(['name' => 'Kauri House', 'is_active' => true, 'archived' => false, 'archived_at' => null]);
    DiscoveryScope::factory()->create([
        'site_id' => $this->site->id, 'collector_id' => null, 'cidrs' => ['10.77.0.0/24'], 'protocols' => ['tcp'],
        'port_bounds' => ['tcp' => [22]], 'exclusions' => [], 'status' => 'active',
    ]);

    $this->router = Device::factory()->create([
        'name' => 'Kauri House router', 'domain' => 'it_infrastructure', 'category' => 'network', 'subcategory' => 'router',
        'manufacturer' => 'MikroTik', 'model' => 'hAP ax3', 'provider' => 'mikrotik', 'ip_address' => '10.77.0.1', 'last_seen_at' => now(),
    ]);
    DeviceAssignment::query()->create([
        'device_id' => $this->router->id, 'assignable_type' => DeviceAssignment::TARGET_SITE, 'assignable_id' => $this->site->id,
        'assignment_type' => 'permanent', 'assigned_at' => now()->subHour(),
    ]);

    CredentialReference::query()->create([
        'reference_key' => 'database:mikrotik/'.$this->site->id.'/ssh', 'site_id' => $this->site->id, 'provider' => 'mikrotik',
        'purpose' => 'device_management', 'capabilities' => ['command:network.config.backup'],
        'secret_manager_reference' => 'secret/data/sites/'.$this->site->id.'/mikrotik-ssh',
        'secret_manager_reference_hash' => hash('sha256', 'mikrotik-ssh-'.$this->site->id),
        'status' => CredentialReferenceStatus::Active, 'rotation_status' => CredentialRotationStatus::Current,
        'test_status' => CredentialTestStatus::Passed, 'version' => 1, 'last_tested_at' => now(),
    ]);

    $this->app->instance(SnapshotStore::class, new class implements SnapshotStore
    {
        /** @var array<string, string> */
        public array $objects = [];

        public function put(string $path, string $contents): void
        {
            $this->objects[$path] = $contents;
        }

        public function read(string $path): string
        {
            return $this->objects[$path] ?? throw new RuntimeException('Missing '.$path);
        }

        public function delete(string $path): void
        {
            unset($this->objects[$path]);
        }

        public function exists(string $path): bool
        {
            return isset($this->objects[$path]);
        }
    });
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
            return new CredentialLease('lease-'.Str::uuid(), now()->toImmutable()->addMinutes(5), ['username' => 'backup_ro', 'password' => 'test-only-password']);
        }
    });

    $this->ssh = new PinnedSshDeviceStandIn;
    $this->app->instance(NetworkCliSshTransport::class, $this->ssh);
    foreach ([ConfigurationSnapshotService::class, ApprovedProbeScopeProvider::class, EgressPolicy::class, CommandCredentialLeaseService::class,
        NetworkSshConfigBackupCommandAdapter::class, CommandExecutionAdapterRegistry::class, DeviceCommandRequestService::class,
        GovernedCommandDispatchService::class, DeviceCommandReconciliationService::class] as $service) {
        $this->app->forgetInstance($service);
    }

    $this->person = function (string $role): User {
        $user = User::factory()->create(['approved_at' => now(), 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
        $user->roles()->attach(Role::query()->where('name', $role)->firstOrFail());
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => $this->site->id, 'secondary_site_ids' => [], 'is_active' => true]);

        return $user;
    };

    $this->backUp = function (User $actor, string $key): CommandStatus {
        $command = app(DeviceCommandRequestService::class)->request($this->router->fresh(), $actor, new CommandRequestInput(
            capability: 'network.config.backup', parameters: [], reason: 'Phase A SSH key check.', idempotencyKey: $key, impactAcknowledged: true,
        ));
        app(GovernedCommandDispatchService::class)->dispatch($command->fresh(), $actor);

        return $command->fresh()->status;
    };
});

it('sends no login to a device whose key is not trusted, and keeps the key it offered', function (): void {
    $manager = ($this->person)('facilities_manager');

    expect(($this->backUp)($manager, 'backup-untrusted'))->toBe(CommandStatus::Failed)
        ->and($this->ssh->calls)->toBe([['expected' => null, 'login_sent' => false]])
        ->and(data_get($this->router->fresh()->meta, 'ssh_host_key_offered.fingerprint'))->toBe(SSH_KEY_ONE)
        ->and(data_get($this->router->fresh()->meta, 'ssh_host_key_offered.changed'))->toBeFalse()
        ->and(ConfigurationSnapshot::query()->count())->toBe(0);

    $this->actingAs($manager)
        ->get("/security-devices/devices/{$this->router->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('profile.configuration.sshHostKey.trusted', null)
            ->where('profile.configuration.sshHostKey.offered.fingerprint', SSH_KEY_ONE)
            ->where('profile.configuration.sshHostKey.can_trust', true)
            ->where('profile.configuration.sshHostKey.trust_url', "/security-devices/devices/{$this->router->id}/ssh-host-key/trust"));
});

it('trusts only the key the device offered, then backs up with the login', function (): void {
    $manager = ($this->person)('facilities_manager');
    ($this->backUp)($manager, 'backup-first');

    $this->actingAs($manager)
        ->post("/security-devices/devices/{$this->router->id}/ssh-host-key/trust", ['fingerprint' => SSH_KEY_TWO])
        ->assertSessionHasErrors('fingerprint');

    $this->actingAs($manager)
        ->post("/security-devices/devices/{$this->router->id}/ssh-host-key/trust", ['fingerprint' => SSH_KEY_ONE])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect(data_get($this->router->fresh()->config, 'ssh.host_key_fingerprint'))->toBe(SSH_KEY_ONE)
        ->and(data_get($this->router->fresh()->meta, 'ssh_host_key_offered'))->toBeNull()
        ->and(DB::table('audit_logs')->where('action', 'security_devices.ssh_host_key.trusted')->count())->toBe(1);

    expect(($this->backUp)($manager, 'backup-trusted'))->not->toBe(CommandStatus::Failed)
        ->and(end($this->ssh->calls))->toBe(['expected' => SSH_KEY_ONE, 'login_sent' => true])
        ->and(ConfigurationSnapshot::query()->where('device_id', $this->router->id)->count())->toBe(1);
});

it('refuses a changed key until an IT admin replaces it', function (): void {
    $manager = ($this->person)('facilities_manager');
    $itAdmin = ($this->person)('it_manager');
    ($this->backUp)($manager, 'backup-first');
    $this->actingAs($manager)->post("/security-devices/devices/{$this->router->id}/ssh-host-key/trust", ['fingerprint' => SSH_KEY_ONE]);

    // Something else now answers at the router's address.
    $this->ssh->presentedKey = SSH_KEY_TWO;

    expect(($this->backUp)($manager, 'backup-changed'))->toBe(CommandStatus::Failed)
        ->and(end($this->ssh->calls))->toBe(['expected' => SSH_KEY_ONE, 'login_sent' => false])
        ->and(data_get($this->router->fresh()->meta, 'ssh_host_key_offered.fingerprint'))->toBe(SSH_KEY_TWO)
        ->and(data_get($this->router->fresh()->meta, 'ssh_host_key_offered.changed'))->toBeTrue();

    $this->actingAs($manager)
        ->post("/security-devices/devices/{$this->router->id}/ssh-host-key/trust", ['fingerprint' => SSH_KEY_TWO])
        ->assertForbidden();
    expect(data_get($this->router->fresh()->config, 'ssh.host_key_fingerprint'))->toBe(SSH_KEY_ONE);

    $this->actingAs($itAdmin)
        ->post("/security-devices/devices/{$this->router->id}/ssh-host-key/trust", ['fingerprint' => SSH_KEY_TWO])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect(data_get($this->router->fresh()->config, 'ssh.host_key_fingerprint'))->toBe(SSH_KEY_TWO)
        ->and(DB::table('audit_logs')->where('action', 'security_devices.ssh_host_key.replaced')->count())->toBe(1);
});

it('keeps people without device-management rights away from trusting keys', function (): void {
    $worker = ($this->person)('support_worker');

    $this->actingAs($worker)
        ->post("/security-devices/devices/{$this->router->id}/ssh-host-key/trust", ['fingerprint' => SSH_KEY_ONE])
        ->assertForbidden();
});
