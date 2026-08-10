<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Monitoring\Contracts\CredentialLeaseProvider;
use App\Domain\Monitoring\Data\CredentialLease;
use App\Domain\SecurityDevices\Credentials\Contracts\SecretManagerLeaseIssuer;
use App\Domain\SecurityDevices\Credentials\Data\SecretLeaseRequest;
use App\Domain\SecurityDevices\Credentials\Enums\CredentialReferenceStatus;
use App\Domain\SecurityDevices\Credentials\Enums\CredentialRotationStatus;
use App\Domain\SecurityDevices\Credentials\Enums\CredentialTestStatus;
use App\Domain\SecurityDevices\Credentials\Models\CredentialLeaseAuditEvent;
use App\Domain\SecurityDevices\Credentials\Models\CredentialLeaseGrant;
use App\Domain\SecurityDevices\Credentials\Models\CredentialReference;
use App\Domain\SecurityDevices\Credentials\Models\CredentialReferenceAuditEvent;
use App\Domain\SecurityDevices\Credentials\Services\CommandCredentialLeaseService;
use App\Domain\SecurityDevices\Credentials\Services\CredentialLeaseLifecycleService;
use App\Domain\SecurityDevices\Credentials\Services\CredentialReferenceManager;
use App\Domain\SecurityDevices\Credentials\Services\HashicorpVaultLeaseIssuer;
use App\Domain\SecurityDevices\Management\Data\CommandExecutionContext;
use App\Domain\SecurityDevices\Models\Device;
use App\Logging\ConfigureSensitiveDataRedaction;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class CredentialContractIssuer implements SecretManagerLeaseIssuer
{
    /** @var list<SecretLeaseRequest> */
    public array $requests = [];

    /** @var list<string> */
    public array $revoked = [];

    public bool $fail = false;

    public bool $failRevocation = false;

    public function issue(SecretLeaseRequest $request): CredentialLease
    {
        $this->requests[] = $request;
        if ($this->fail) {
            throw new RuntimeException('Fixture issuer rejected the reference.');
        }

        return new CredentialLease(
            'contract-lease-'.count($this->requests),
            $request->expiresAt,
            ['username' => 'runtime-user', 'password' => 'runtime-secret-sentinel'],
        );
    }

    public function revoke(string $leaseId): void
    {
        if ($this->failRevocation) {
            throw new RuntimeException('Fixture issuer could not revoke the lease.');
        }
        $this->revoked[] = $leaseId;
    }
}

function credentialContractAdmin(): User
{
    $actor = User::factory()->create(['approved_at' => now()]);
    $actor->roles()->attach(Role::query()->where('name', 'admin')->firstOrFail());

    return $actor;
}

function credentialContractScopedAdmin(Site $site): User
{
    $actor = User::factory()->create(['approved_at' => now()]);
    HrEmployeeProfile::factory()->create([
        'user_id' => $actor->id,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        'is_active' => true,
    ]);
    $permissions = Permission::query()
        ->whereIn('key', ['securityDevices.viewAny', 'securityDevices.commands.admin'])
        ->pluck('id');
    $actor->permissionOverrides()->syncWithoutDetaching(
        $permissions->mapWithKeys(fn (int $id): array => [$id => ['allowed' => true]])->all(),
    );
    $actor->unsetRelation('permissionOverrides')->unsetRelation('roles');

    return $actor;
}

/** @return array<string, mixed> */
function credentialContractAttributes(Site $site, array $overrides = []): array
{
    return array_replace([
        'site_id' => $site->id,
        'reference_key' => 'vault:network/'.$site->id.'/core-switch',
        'provider' => 'unifi',
        'purpose' => 'device_management',
        'capabilities' => ['command:network.device.reboot', 'inventory:ssh:read_only'],
        'secret_manager_reference' => 'secret/data/sites/'.$site->id.'/core-switch',
    ], $overrides);
}

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->seed(SecurityDevicesPermissionsSeeder::class);
    config()->set('monitoring.credentials.lease_ttl_seconds', 60);
    $this->issuer = new CredentialContractIssuer;
    app()->instance(SecretManagerLeaseIssuer::class, $this->issuer);
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

it('stores only encrypted external references and safe single-application Site metadata', function () {
    $site = Site::factory()->create();
    $reference = app(CredentialReferenceManager::class)->register(
        credentialContractAdmin(),
        credentialContractAttributes($site),
    );
    $stored = DB::table('security_device_credential_references')->where('id', $reference->id)->first();
    $audit = CredentialReferenceAuditEvent::query()->sole();

    expect(Schema::hasColumns('security_device_credential_references', [
        'reference_uuid', 'reference_key', 'site_id', 'provider', 'purpose', 'capabilities',
        'secret_manager_reference', 'rotation_status', 'test_status', 'version',
    ]))->toBeTrue()
        ->and($reference->status)->toBe(CredentialReferenceStatus::Suspended)
        ->and($reference->rotation_status)->toBe(CredentialRotationStatus::Due)
        ->and($reference->test_status)->toBe(CredentialTestStatus::Untested)
        ->and($stored->secret_manager_reference)->not->toContain('secret/data/sites')
        ->and($reference->toArray())->not->toHaveKeys([
            'secret_manager_reference', 'secret_manager_reference_hash',
        ])
        ->and(json_encode($audit->safe_context, JSON_THROW_ON_ERROR))->not->toContain('secret/data/sites')
        ->and($audit->action)->toBe('registered');
});

it('activates only after a live secret-manager test and issues an exact short-lived one-use lease', function () {
    $site = Site::factory()->create();
    $manager = app(CredentialReferenceManager::class);
    $reference = $manager->register(credentialContractAdmin(), credentialContractAttributes($site));
    $reference = $manager->test($reference->createdBy, $reference);

    expect($reference->status)->toBe(CredentialReferenceStatus::Active)
        ->and($reference->rotation_status)->toBe(CredentialRotationStatus::Current)
        ->and($reference->test_status)->toBe(CredentialTestStatus::Passed)
        ->and($this->issuer->revoked)->toBe(['contract-lease-1']);

    $lease = app(CredentialLeaseProvider::class)->acquire(
        $site->id,
        $reference->reference_key,
        ['command:network.device.reboot'],
    );
    expect($lease->expiresAt->diffInSeconds(CarbonImmutable::now('UTC')))->toBeLessThanOrEqual(60)
        ->and($lease->material())->toBe([
            'username' => 'runtime-user',
            'password' => 'runtime-secret-sentinel',
        ])
        ->and(fn () => $lease->material())->toThrow(RuntimeException::class, 'already been consumed')
        ->and(CredentialLeaseAuditEvent::query()->where('action', 'issued')->count())->toBe(1);

    $leaseAudit = CredentialLeaseAuditEvent::query()->where('action', 'issued')->sole();
    expect(json_encode($leaseAudit, JSON_THROW_ON_ERROR))->not->toContain('runtime-secret-sentinel')
        ->not->toContain($reference->reference_key);
});

it('conceals wrong Site and capability requests without calling the secret manager', function () {
    $allowedSite = Site::factory()->create();
    $hiddenSite = Site::factory()->create();
    $manager = app(CredentialReferenceManager::class);
    $admin = credentialContractAdmin();
    $reference = $manager->test(
        $admin,
        $manager->register($admin, credentialContractAttributes($allowedSite)),
    );
    $callsBefore = count($this->issuer->requests);

    expect(fn () => app(CredentialLeaseProvider::class)->acquire(
        $hiddenSite->id,
        $reference->reference_key,
        ['command:network.device.reboot'],
    ))->toThrow(RuntimeException::class, 'Credential lease is unavailable.')
        ->and(fn () => app(CredentialLeaseProvider::class)->acquire(
            $allowedSite->id,
            $reference->reference_key,
            ['command:firmware.upgrade'],
        ))->toThrow(RuntimeException::class, 'Credential lease is unavailable.')
        ->and(count($this->issuer->requests))->toBe($callsBefore)
        ->and(CredentialLeaseAuditEvent::query()->where('action', 'denied')->count())->toBe(2);
});

it('rotates in place suspends until retested and revokes without rewriting the reference identity', function () {
    $site = Site::factory()->create();
    $manager = app(CredentialReferenceManager::class);
    $admin = credentialContractAdmin();
    $reference = $manager->test($admin, $manager->register($admin, credentialContractAttributes($site)));
    $uuid = $reference->reference_uuid;
    $version = $reference->version;

    $reference = $manager->rotate($admin, $reference, 'secret/data/sites/'.$site->id.'/core-switch-v2');
    expect($reference->reference_uuid)->toBe($uuid)
        ->and($reference->version)->toBe($version + 1)
        ->and($reference->status)->toBe(CredentialReferenceStatus::Suspended)
        ->and($reference->test_status)->toBe(CredentialTestStatus::Untested)
        ->and(fn () => app(CredentialLeaseProvider::class)->acquire(
            $site->id,
            $reference->reference_key,
            ['command:network.device.reboot'],
        ))->toThrow(RuntimeException::class, 'Credential lease is unavailable.');

    $reference = $manager->test($admin, $reference);
    $reference = $manager->revoke($admin, $reference);
    expect($reference->reference_uuid)->toBe($uuid)
        ->and($reference->status)->toBe(CredentialReferenceStatus::Revoked)
        ->and(fn () => $reference->delete())->toThrow(UnexpectedValueException::class)
        ->and(CredentialReferenceAuditEvent::query()->pluck('action')->all())
        ->toBe(['registered', 'test_passed', 'rotated', 'test_passed', 'revoked']);
});

it('contains every outstanding lease during rotation and erases its recoverable identifier', function () {
    CarbonImmutable::setTestNow('2026-07-27T01:00:00Z');
    $site = Site::factory()->create();
    $manager = app(CredentialReferenceManager::class);
    $admin = credentialContractAdmin();
    $reference = $manager->test($admin, $manager->register($admin, credentialContractAttributes($site)));
    $lease = app(CredentialLeaseProvider::class)->acquire(
        $site->id,
        $reference->reference_key,
        ['command:network.device.reboot'],
    );
    $grant = CredentialLeaseGrant::query()->sole();
    $storedBefore = DB::table('security_device_credential_lease_grants')->value('lease_id');

    $rotated = $manager->rotate(
        $admin,
        $reference,
        'secret/data/sites/'.$site->id.'/core-switch-contained',
    );
    $grant->refresh();

    expect($storedBefore)->not->toContain($lease->leaseId)
        ->and($rotated->status)->toBe(CredentialReferenceStatus::Suspended)
        ->and($grant->status)->toBe(CredentialLeaseGrant::STATUS_CONTAINED)
        ->and($grant->lease_id)->toBeNull()
        ->and($grant->ended_at?->equalTo(CarbonImmutable::now('UTC')))->toBeTrue()
        ->and($this->issuer->revoked)->toContain($lease->leaseId)
        ->and(CredentialLeaseAuditEvent::query()->where('action', 'contained')->count())->toBe(1)
        ->and(json_encode($grant, JSON_THROW_ON_ERROR))->not->toContain($lease->leaseId, 'runtime-secret-sentinel');
});

it('retries failed compromise containment and irreversibly erases lease identifiers at authoritative expiry', function () {
    CarbonImmutable::setTestNow('2026-07-27T02:00:00Z');
    $site = Site::factory()->create();
    $manager = app(CredentialReferenceManager::class);
    $admin = credentialContractAdmin();
    $reference = $manager->test($admin, $manager->register($admin, credentialContractAttributes($site)));
    app(CredentialLeaseProvider::class)->acquire(
        $site->id,
        $reference->reference_key,
        ['command:network.device.reboot'],
    );
    $this->issuer->failRevocation = true;

    $manager->rotate($admin, $reference, 'secret/data/sites/'.$site->id.'/core-switch-recovery');
    $grant = CredentialLeaseGrant::query()->sole();
    expect($grant->status)->toBe(CredentialLeaseGrant::STATUS_REVOKE_PENDING)
        ->and($grant->lease_id)->not->toBeNull()
        ->and($grant->last_failure_code)->toBe('provider_revoke_failed');

    CarbonImmutable::setTestNow('2026-07-27T02:01:01Z');
    $result = app(CredentialLeaseLifecycleService::class)->reconcile();
    $grant->refresh();
    expect($result['expired'])->toBe(1)
        ->and($grant->status)->toBe(CredentialLeaseGrant::STATUS_EXPIRED)
        ->and($grant->lease_id)->toBeNull()
        ->and($grant->last_failure_code)->toBe('expired_provider_unavailable')
        ->and(CredentialLeaseAuditEvent::query()->pluck('action')->all())
        ->toContain('revoke_deferred', 'expired');
});

it('rehearses compromised credential containment rotation and replacement verification through the runbook command', function () {
    CarbonImmutable::setTestNow('2026-07-27T03:00:00Z');
    $site = Site::factory()->create();
    $manager = app(CredentialReferenceManager::class);
    $admin = credentialContractAdmin();
    $reference = $manager->test($admin, $manager->register($admin, credentialContractAttributes($site)));
    $oldLease = app(CredentialLeaseProvider::class)->acquire(
        $site->id,
        $reference->reference_key,
        ['command:network.device.reboot'],
    );
    $reference = $manager->rotate(
        $admin,
        $reference,
        'secret/data/sites/'.$site->id.'/core-switch-rehearsal-replacement',
    );
    expect(Artisan::call('security-devices:verify-credential-containment', [
        'site_id' => $site->id,
        'reference_key' => $reference->reference_key,
        '--require-active' => true,
    ]))->toBe(1)
        ->and(Artisan::output())->toContain('not passed activation testing')
        ->not->toContain('runtime-secret-sentinel', $oldLease->leaseId);

    $reference = $manager->test($admin, $reference);
    expect(Artisan::call('security-devices:verify-credential-containment', [
        'site_id' => $site->id,
        'reference_key' => $reference->reference_key,
        '--require-active' => true,
    ]))->toBe(0)
        ->and(Artisan::output())->toContain(
            'Credential containment verified.',
            'Outstanding prior leases: 0',
        )->not->toContain(
            'runtime-secret-sentinel',
            $oldLease->leaseId,
            'secret/data/sites',
        );

    $priorGrant = CredentialLeaseGrant::query()
        ->where('credential_reference_id', $reference->id)
        ->where('reference_version', '<', $reference->version)
        ->sole();
    expect($priorGrant->status)->toBe(CredentialLeaseGrant::STATUS_CONTAINED)
        ->and($priorGrant->ended_at)->not->toBeNull();
    $priorGrant->forceFill(['lease_id' => 'corrupted-terminal-lease-identifier'])->save();

    expect(Artisan::call('security-devices:verify-credential-containment', [
        'site_id' => $site->id,
        'reference_key' => $reference->reference_key,
        '--require-active' => true,
    ]))->toBe(1)
        ->and(Artisan::output())->toContain('prior lease lifecycle record(s) are not terminal and erased')
        ->not->toContain('corrupted-terminal-lease-identifier');
});

it('enforces command-admin permission and exact Site access on reference management', function () {
    $allowedSite = Site::factory()->create();
    $hiddenSite = Site::factory()->create();
    $scopedAdmin = credentialContractScopedAdmin($allowedSite);

    expect(fn () => app(CredentialReferenceManager::class)->register(
        $scopedAdmin,
        credentialContractAttributes($hiddenSite),
    ))->toThrow(NotFoundHttpException::class)
        ->and(CredentialReference::query()->count())->toBe(0);
});

it('projects only safe credential metadata for the administrators exact Site scope', function () {
    $allowedSite = Site::factory()->create(['name' => 'Allowed Credential Site']);
    $hiddenSite = Site::factory()->create(['name' => 'Hidden Credential Site']);
    $admin = credentialContractAdmin();
    app(CredentialReferenceManager::class)->register(
        $admin,
        credentialContractAttributes($hiddenSite),
    );
    $scopedAdmin = credentialContractScopedAdmin($allowedSite);

    $this->actingAs($scopedAdmin)
        ->get('/security-devices/settings')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('security-devices/settings')
            ->where('credentialReferences.visible', true)
            ->where('credentialReferences.driver_state', 'unavailable')
            ->has('credentialReferences.sites', 1)
            ->where('credentialReferences.sites.0.id', $allowedSite->id)
            ->has('credentialReferences.rows', 0));

    $this->actingAs($admin)
        ->get('/security-devices/settings')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('credentialReferences.rows', 1)
            ->where('credentialReferences.rows.0.site_name', 'Hidden Credential Site')
            ->where('credentialReferences.rows.0.reference_key', 'vault:network/'.$hiddenSite->id.'/core-switch')
            ->missing('credentialReferences.rows.0.secret_manager_reference')
            ->missing('credentialReferences.rows.0.secret_manager_reference_hash'));
});

it('provides register test rotate and revoke routes without accepting credential material', function () {
    $site = Site::factory()->create();
    $admin = credentialContractAdmin();
    $attributes = credentialContractAttributes($site);

    $this->actingAs($admin)
        ->post('/security-devices/settings/credential-references', $attributes)
        ->assertRedirect()
        ->assertSessionHas('success');
    $reference = CredentialReference::query()->sole();
    expect($reference->status)->toBe(CredentialReferenceStatus::Suspended);

    $this->actingAs($admin)
        ->post("/security-devices/settings/credential-references/{$reference->reference_uuid}/test")
        ->assertRedirect()
        ->assertSessionHas('success');
    expect($reference->fresh()->status)->toBe(CredentialReferenceStatus::Active);

    $this->actingAs($admin)
        ->post("/security-devices/settings/credential-references/{$reference->reference_uuid}/rotate", [
            'secret_manager_reference' => 'secret/data/sites/'.$site->id.'/core-switch-v2',
        ])
        ->assertRedirect()
        ->assertSessionHas('success');
    expect($reference->fresh()->status)->toBe(CredentialReferenceStatus::Suspended);

    $this->actingAs($admin)
        ->post("/security-devices/settings/credential-references/{$reference->reference_uuid}/revoke")
        ->assertRedirect()
        ->assertSessionHas('success');
    expect($reference->fresh()->status)->toBe(CredentialReferenceStatus::Revoked)
        ->and(DB::table('security_device_credential_references')->value('secret_manager_reference'))
        ->not->toContain('core-switch-v2');

    $this->actingAs($admin)
        ->post('/security-devices/settings/credential-references', array_replace($attributes, [
            'reference_key' => 'vault:network/'.$site->id.'/raw-secret',
            'secret_manager_reference' => 'https://vault.example.test/raw-password-value',
        ]))
        ->assertSessionHasErrors('reference_key')
        ->assertSessionMissing('_old_input.secret_manager_reference');
    expect(CredentialReference::query()->count())->toBe(1);
});

it('resolves command credentials only from the exact Site provider purpose and capability', function () {
    $site = Site::factory()->create();
    $admin = credentialContractAdmin();
    $manager = app(CredentialReferenceManager::class);
    $manager->test($admin, $manager->register($admin, credentialContractAttributes($site)));
    $device = Device::factory()->itInfrastructure()->create(['provider' => 'unifi']);
    $context = new CommandExecutionContext(
        commandUuid: '019f7b90-a3cc-7c6b-8428-766011e76001',
        attemptUuid: '019f7b90-a3cc-7c6b-8428-766011e76002',
        attemptNumber: 1,
        device: $device,
        siteId: $site->id,
        capability: 'network.device.reboot',
        parameters: [],
        expectedState: ['reachable' => true],
        idempotencyKey: 'credential-contract-command-1',
        expiresAt: CarbonImmutable::now('UTC')->addMinute(),
    );

    $lease = app(CommandCredentialLeaseService::class)->acquire($context);
    expect($lease->material()['username'])->toBe('runtime-user');

    $device->provider = 'other-provider';
    expect(fn () => app(CommandCredentialLeaseService::class)->acquire($context))
        ->toThrow(RuntimeException::class, 'A governed command credential is unavailable.');
});

it('retrieves Vault material over HTTPS without serialising the external path or material', function () {
    CarbonImmutable::setTestNow('2026-07-24T01:00:00Z');
    config()->set('monitoring.credentials.vault.url', 'https://vault.oblivion.test');
    config()->set('monitoring.credentials.vault.token', 'vault-bootstrap-token-sentinel');
    config()->set('monitoring.credentials.vault.namespace', 'oblivion');
    Http::fake([
        'https://vault.oblivion.test/v1/secret/data/sites/9/core-switch' => Http::response([
            'lease_id' => 'database/creds/network/lease-1',
            'lease_duration' => 45,
            'data' => ['data' => [
                'username' => 'vault-runtime-user',
                'password' => 'vault-runtime-secret-sentinel',
            ]],
        ]),
    ]);
    $request = new SecretLeaseRequest(
        referenceUuid: '019f7b90-a3cc-7c6b-8428-766011e76003',
        siteId: 9,
        provider: 'unifi',
        purpose: 'device_management',
        capabilities: ['command:network.device.reboot'],
        externalReference: 'secret/data/sites/9/core-switch',
        expiresAt: CarbonImmutable::now('UTC')->addMinute(),
    );
    $lease = (new HashicorpVaultLeaseIssuer)->issue($request);

    expect($lease->expiresAt->toISOString())->toBe('2026-07-24T01:00:45.000000Z')
        ->and(json_encode($request, JSON_THROW_ON_ERROR))->not->toContain('secret/data/sites')
        ->and(json_encode($lease, JSON_THROW_ON_ERROR))->not->toContain('vault-runtime-secret-sentinel')
        ->and($lease->material()['username'])->toBe('vault-runtime-user');
    Http::assertSent(fn (Request $sent): bool => $sent->url() === 'https://vault.oblivion.test/v1/secret/data/sites/9/core-switch'
        && $sent->hasHeader('X-Vault-Token', 'vault-bootstrap-token-sentinel')
        && $sent->hasHeader('X-Vault-Namespace', 'oblivion'));
});

it('fails closed for insecure Vault configuration and never makes a request', function () {
    config()->set('monitoring.credentials.vault.url', 'http://vault.oblivion.test');
    config()->set('monitoring.credentials.vault.token', 'vault-bootstrap-token-sentinel');
    Http::fake();
    $request = new SecretLeaseRequest(
        referenceUuid: '019f7b90-a3cc-7c6b-8428-766011e76004',
        siteId: 9,
        provider: 'unifi',
        purpose: 'device_management',
        capabilities: ['command:network.device.reboot'],
        externalReference: 'secret/data/sites/9/core-switch',
        expiresAt: CarbonImmutable::now('UTC')->addMinute(),
    );

    expect(fn () => (new HashicorpVaultLeaseIssuer)->issue($request))
        ->toThrow(RuntimeException::class, 'Vault is not securely configured.');
    Http::assertNothingSent();
});

it('redacts credential material from a real Laravel log channel including exception context', function () {
    $path = tempnam(sys_get_temp_dir(), 'oblivion-m05-log-');
    $sentinel = 'm05-laravel-log-secret-sentinel';
    try {
        config()->set('logging.channels.m05_redaction_test', [
            'driver' => 'single',
            'path' => $path,
            'level' => 'debug',
            'tap' => [ConfigureSensitiveDataRedaction::class],
        ]);
        Log::forgetChannel('m05_redaction_test');
        $logger = Log::channel('m05_redaction_test');
        $logger->error('Provider failed with Bearer '.$sentinel, [
            'password' => $sentinel,
            'material' => ['private_key' => $sentinel],
            'exception' => new RuntimeException('lease_id='.$sentinel),
            'site_id' => 9,
        ]);
        $written = file_get_contents($path);

        expect($written)->not->toContain($sentinel)
            ->and($written)->toContain('[REDACTED]', 'site_id', 'exception_class');
    } finally {
        Log::forgetChannel('m05_redaction_test');
        config()->set('logging.channels.m05_redaction_test', null);
        if (is_string($path) && is_file($path)) {
            unlink($path);
        }
    }
});
