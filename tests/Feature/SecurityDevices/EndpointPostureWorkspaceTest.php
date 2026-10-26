<?php

use App\Domain\SecurityDevices\Endpoints\Models\EndpointAgent;
use App\Domain\SecurityDevices\Endpoints\Models\EndpointDelivery;
use App\Domain\SecurityDevices\Endpoints\Models\IntuneDeviceLink;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointActionPolicy;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointHeartbeat;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointScriptPolicy;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointServiceCommands;
use App\Domain\SecurityDevices\Management\Data\CommandDecisionInput;
use App\Domain\SecurityDevices\Management\Data\CommandRequestInput;
use App\Domain\SecurityDevices\Management\Enums\CommandApprovalDecision;
use App\Domain\SecurityDevices\Management\Services\CommandAssignmentFingerprint;
use App\Domain\SecurityDevices\Management\Services\DeviceCommandApprovalService;
use App\Domain\SecurityDevices\Management\Services\DeviceCommandRequestService;
use App\Domain\SecurityDevices\Management\Services\GovernedCommandDispatchService;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

function endpointPostureUser(Site $site, array $keys, bool $mfa = false): User
{
    $role = Role::query()->create([
        'name' => 'endpoint-posture-'.Str::uuid(),
        'label' => 'Endpoint posture test role',
        'level' => 10,
        'type' => 'custom',
    ]);
    $user = User::factory()->create([
        'approved_at' => now(),
        'role' => $role->name,
        'two_factor_confirmed_at' => $mfa ? now() : null,
    ]);
    $user->roles()->attach($role);

    foreach ($keys as $key) {
        $permission = Permission::query()->firstOrCreate(
            ['key' => $key],
            ['name' => $key, 'description' => $key, 'group' => 'securityDevices', 'module' => 'securityDevices'],
        );
        $role->permissions()->syncWithoutDetaching([$permission->id]);
    }

    ensureCanonicalHrStaffProfile($user, $site);

    return $user->fresh();
}

function endpointPostureComputer(Site $site, string $category = 'endpoint', string $name = 'Kauri Reception PC'): Device
{
    $device = Device::factory()->create([
        'name' => $name,
        'domain' => 'it_infrastructure',
        'category' => $category,
        'provider' => 'oblivion_native',
        'status' => 'active',
        'last_seen_at' => now(),
    ]);

    DeviceAssignment::query()->create([
        'device_id' => $device->id,
        'assignable_type' => DeviceAssignment::TARGET_SITE,
        'assignable_id' => $site->id,
        'assignment_type' => 'permanent',
        'assigned_at' => now()->subMinutes(5),
    ]);

    return $device;
}

function endpointPostureAgent(Device $device, Site $site, User $creator, ?string $credential = null): EndpointAgent
{
    $rawCredential = $credential ?? Str::random(64);

    return EndpointAgent::query()->create([
        'uuid' => (string) Str::uuid(),
        'device_id' => $device->id,
        'site_id' => $site->id,
        'platform' => 'windows',
        'created_by_user_id' => $creator->id,
        'assignment_fingerprint' => app(CommandAssignmentFingerprint::class)->forDevice($device),
        'credential_hash' => hash('sha256', $rawCredential),
        'agent_version' => '1.2.0',
        'enrolled_at' => now()->subDay(),
        'last_seen_at' => now()->subSeconds(30),
        'observed_at' => now()->subSeconds(30),
        'sequence' => 7,
        'heartbeat_hash' => str_repeat('b', 64),
        'service_actions' => [
            'version' => 1,
            'installed_service' => true,
            'release_enabled' => true,
            'executor' => 'windows_scm',
            'operations' => ['start', 'stop', 'restart'],
        ],
        'inventory' => [
            'hostname' => 'KAURI-SECRET-HOSTNAME-01',
            'os_version' => 'Windows 11 Pro 24H2',
            'architecture' => 'amd64',
            'memory_total_bytes' => 17179869184,
            'memory_available_bytes' => 8589934592,
            'uptime_seconds' => 86400,
            'cpu_percent' => 23.4,
            'disks' => [
                ['name' => 'C:', 'total_bytes' => 256000000000, 'free_bytes' => 128000000000],
            ],
            'services' => [
                ['name' => 'wuauserv', 'state' => 'stopped'],
                ['name' => 'Spooler', 'state' => 'running'],
            ],
            'services_complete' => true,
            'software' => [
                ['name' => 'Confidential Diagnostic Tool', 'version' => '4.1.0', 'publisher' => 'Acme'],
            ],
            'software_complete' => true,
            'probe_errors' => [],
        ],
    ]);
}

beforeEach(function () {
    $this->seed([RbacSeeder::class, SecurityDevicesPermissionsSeeder::class]);
    Http::preventStrayRequests();
    Queue::fake();

    config()->set('endpoint_management.enabled', true);
    config()->set('endpoint_management.actions.release_enabled', true);
    config()->set('endpoint_management.server_url', 'https://localhost');
    config()->set('endpoint_management.services', [
        ['name' => 'Spooler', 'label' => 'Print Spooler', 'operations' => ['start', 'stop', 'restart']],
    ]);
    config()->set('endpoint_management.intune.enabled', true);

    $this->site = Site::factory()->create([
        'name' => 'Kauri House',
        'is_active' => true,
        'archived' => false,
    ]);
    $this->admin = User::factory()->create(['approved_at' => now()]);
    $this->admin->roles()->attach(Role::query()->where('name', 'admin')->firstOrFail());
    ensureCanonicalHrStaffProfile($this->admin, $this->site);
});

it('projects endpoint posture and section for canonical computers and omits them for non-computers', function () {
    $computer = endpointPostureComputer($this->site, 'endpoint');
    $server = endpointPostureComputer($this->site, 'server', 'Kauri File Server');
    $gateway = endpointPostureComputer($this->site, 'networking', 'Kauri Edge Gateway');

    endpointPostureAgent($computer, $this->site, $this->admin);
    endpointPostureAgent($server, $this->site, $this->admin);

    $this->actingAs($this->admin)
        ->get("/security-devices/devices/{$computer->id}")
        ->assertOk()
        ->assertInertia(function ($page): void {
            $profile = $page->toArray()['props']['profile'];
            $sectionKeys = collect($profile['sections'])->pluck('key')->all();

            expect($sectionKeys)->toContain('endpoint')
                ->and($profile['endpointPosture']['agent']['state'])->toBe('fresh')
                ->and($profile['endpointPosture']['diagnosticsRestricted'])->toBeFalse()
                ->and($profile['endpointPosture']['inventory']['hostname'])->toBe('KAURI-SECRET-HOSTNAME-01')
                ->and($profile['endpointPosture']['inventory']['services'][0]['name'])->toBe('Spooler')
                ->and($profile['endpointPosture']['inventory']['services'][0]['approved'])->toBeTrue()
                ->and($profile['endpointPosture']['policies']['script']['available'])->toBeFalse()
                ->and(app(EndpointScriptPolicy::class)->available())->toBeFalse();
        });

    $this->actingAs($this->admin)
        ->get("/security-devices/devices/{$server->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('profile.endpointPosture.agent.state', 'fresh'));

    $this->actingAs($this->admin)
        ->get("/security-devices/devices/{$gateway->id}")
        ->assertOk()
        ->assertInertia(function ($page): void {
            $profile = $page->toArray()['props']['profile'];
            $sectionKeys = collect($profile['sections'])->pluck('key')->all();

            expect($sectionKeys)->not->toContain('endpoint')
                ->and($profile['endpointPosture'])->toBeNull();
        });
});

it('withholds diagnostic inventory from viewers without endpoint diagnostics permission and on assignment drift', function () {
    $computer = endpointPostureComputer($this->site);
    endpointPostureAgent($computer, $this->site, $this->admin);

    $integrationViewer = endpointPostureUser($this->site, [
        'securityDevices.viewAny',
        'securityDevices.devices.view',
        'securityDevices.integrations.view',
    ]);

    $response = $this->actingAs($integrationViewer)
        ->get("/security-devices/devices/{$computer->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('profile.endpointPosture.agent.state', 'fresh')
            ->where('profile.endpointPosture.diagnosticsRestricted', true)
            ->where('profile.endpointPosture.inventory', null));

    $response->assertDontSee('KAURI-SECRET-HOSTNAME-01');
    $response->assertDontSee('Confidential Diagnostic Tool');

    // Move the computer to another accessible Site after enrolment -> assignment_drift withholds inventory even for admin.
    $otherSite = Site::factory()->create(['name' => 'Totara House', 'is_active' => true, 'archived' => false]);
    ensureCanonicalHrStaffProfile($this->admin, $this->site, ['secondary_site_ids' => [$otherSite->id]]);
    DeviceAssignment::query()->where('device_id', $computer->id)->update(['released_at' => now()->subSecond()]);
    DeviceAssignment::query()->create([
        'device_id' => $computer->id,
        'assignable_type' => DeviceAssignment::TARGET_SITE,
        'assignable_id' => $otherSite->id,
        'assignment_type' => 'permanent',
        'assigned_at' => now(),
    ]);

    $driftResponse = $this->actingAs($this->admin)
        ->get("/security-devices/devices/{$computer->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('profile.endpointPosture.agent.state', 'assignment_drift')
            ->where('profile.endpointPosture.inventory', null));

    $driftResponse->assertDontSee('KAURI-SECRET-HOSTNAME-01');
});

it('projects Windows service command outcomes and readback on device management history', function () {
    config()->set('endpoint_management.command_signing_secret', base64_encode(sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair(str_repeat('s', 32)))));
    config()->set('monitoring.signing.active_key_id', 'posture-service-test');
    config()->set('monitoring.signing.keys', ['posture-service-test' => base64_encode(str_repeat('S', SODIUM_CRYPTO_AUTH_KEYBYTES))]);

    $credential = str_repeat('c', 64);
    $computer = endpointPostureComputer($this->site);
    endpointPostureAgent($computer, $this->site, $this->admin, $credential);
    DB::table('endpoint_action_policies')->insert([
        'device_id' => $computer->id,
        'version' => 1,
        'enabled' => true,
        'consent_mode' => 'attended',
        'updated_by_user_id' => $this->admin->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $operator = endpointPostureUser($this->site, [
        'it.view',
        'it.manage',
        'securityDevices.viewAny',
        'securityDevices.devices.view',
        'securityDevices.commands.observe',
        'securityDevices.commands.control',
        EndpointActionPolicy::SERVICE,
    ], mfa: true);
    $reviewer = endpointPostureUser($this->site, [
        'it.view',
        'it.manage',
        'securityDevices.viewAny',
        'securityDevices.devices.view',
        'securityDevices.commands.observe',
        'securityDevices.commands.approve',
        EndpointActionPolicy::SERVICE,
    ]);

    $request = app(DeviceCommandRequestService::class)->request(
        $computer,
        $operator,
        new CommandRequestInput(
            'endpoint.service.manage',
            ['service_name' => 'Spooler', 'operation' => 'restart'],
            'Restart the stuck Print Spooler service for Kauri reception.',
            (string) Str::uuid(),
            CarbonImmutable::now('UTC')->startOfSecond(),
            impactAcknowledged: true,
        ),
    );
    app(DeviceCommandApprovalService::class)->decide(
        $request,
        $reviewer,
        new CommandDecisionInput(CommandApprovalDecision::Approved, 'Reviewed Print Spooler restart.'),
    );
    $attempt = app(GovernedCommandDispatchService::class)->dispatch($request->fresh(), $operator);
    $delivery = EndpointDelivery::query()->where('device_command_attempt_id', $attempt->id)->firstOrFail();

    app(EndpointServiceCommands::class)->claim($credential, $attempt->attempt_uuid, [
        'command_uuid' => $request->command_uuid,
        'payload_hash' => $delivery->payload_hash,
        'consent' => [
            'desktop_session' => '11111111-1111-4111-8111-111111111111',
            'consent_nonce' => str_repeat('d', 64),
        ],
    ]);
    app(EndpointServiceCommands::class)->result($credential, $attempt->attempt_uuid, [
        'command_uuid' => $request->command_uuid,
        'payload_hash' => $delivery->payload_hash,
        'status' => 'succeeded',
        'operation' => 'restart',
        'service_name' => 'Spooler',
        'before_state' => 'stopped',
        'after_state' => 'running',
        'mutation_started' => true,
        'reason_code' => 'confirmed',
    ]);
    $this->travel(3)->seconds();
    app(EndpointHeartbeat::class)->record($credential, [
        'sequence' => 8,
        'observed_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
        'agent_version' => '1.2.0',
        'boot_id' => '22222222-2222-4222-8222-222222222222',
        'service_actions' => [
            'version' => 1,
            'installed_service' => true,
            'release_enabled' => true,
            'executor' => 'windows_scm',
            'operations' => ['start', 'stop', 'restart'],
        ],
        'service_action_observations' => [[
            'command_uuid' => $request->command_uuid,
            'attempt_uuid' => $attempt->attempt_uuid,
            'service_name' => 'Spooler',
            'state' => 'running',
            'observed_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
        ]],
        'inventory' => [
            'hostname' => 'KAURI-SECRET-HOSTNAME-01',
            'os_version' => 'Windows 11 Pro 24H2',
            'architecture' => 'amd64',
            'memory_total_bytes' => 8192,
            'memory_available_bytes' => 4096,
            'uptime_seconds' => 120,
            'cpu_percent' => 12.0,
            'disks' => [],
            'software' => [],
            'software_complete' => true,
            'services' => [['name' => 'Spooler', 'state' => 'running']],
            'services_complete' => true,
            'probe_errors' => [],
        ],
    ]);

    $this->actingAs($this->admin)
        ->get("/security-devices/devices/{$computer->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('profile.management.history.0.capability', 'endpoint.service.manage')
            ->where('profile.management.history.0.service.serviceName', 'Spooler')
            ->where('profile.management.history.0.service.serviceLabel', 'Print Spooler')
            ->where('profile.management.history.0.service.operation', 'restart')
            ->where('profile.management.history.0.service.beforeState', 'stopped')
            ->where('profile.management.history.0.service.afterState', 'running')
            ->where('profile.management.history.0.service.reasonCode', 'confirmed')
            ->where('profile.management.history.0.service.readbackState', 'running'));
});

it('governs Microsoft Intune link creation, conflict rejection, UUID replacement, and reason-gated removal', function () {
    $computer = endpointPostureComputer($this->site);
    $otherComputer = endpointPostureComputer($this->site, 'server', 'Kauri App Server');
    $switch = endpointPostureComputer($this->site, 'networking', 'Kauri Switch');
    $elsewhereSite = Site::factory()->create(['name' => 'Other Site', 'is_active' => true, 'archived' => false]);
    $elsewhereComputer = endpointPostureComputer($elsewhereSite, 'endpoint', 'Elsewhere PC');

    $manager = endpointPostureUser($this->site, [
        'securityDevices.viewAny',
        'securityDevices.devices.view',
        'securityDevices.devices.update',
        'securityDevices.integrations.manage',
    ]);
    $viewerOnly = endpointPostureUser($this->site, [
        'securityDevices.viewAny',
        'securityDevices.devices.view',
        'securityDevices.integrations.view',
    ]);

    $uuidOne = '11111111-2222-4333-8444-555555555555';
    $uuidTwo = '66666666-7777-4888-9999-aaaaaaaaaaaa';

    $this->actingAs($viewerOnly)
        ->postJson("/security-devices/devices/{$computer->id}/endpoint/intune-link", [
            'managed_device_id' => $uuidOne,
        ])
        ->assertForbidden();

    $this->actingAs($manager)
        ->postJson("/security-devices/devices/{$elsewhereComputer->id}/endpoint/intune-link", [
            'managed_device_id' => $uuidOne,
        ])
        ->assertNotFound();

    $this->actingAs($manager)
        ->postJson("/security-devices/devices/{$switch->id}/endpoint/intune-link", [
            'managed_device_id' => $uuidOne,
        ])
        ->assertStatus(422);

    $this->actingAs($manager)
        ->postJson("/security-devices/devices/{$computer->id}/endpoint/intune-link", [
            'managed_device_id' => strtoupper($uuidOne),
            'reason' => 'Matched serial number in Microsoft Intune.',
        ])
        ->assertOk()
        ->assertJsonPath('managed_device_id', $uuidOne);

    $link = IntuneDeviceLink::query()->where('device_id', $computer->id)->firstOrFail();
    expect($link->sync_status)->toBe('pending');
    expect(DeviceEvent::query()->where('device_id', $computer->id)->where('event_type', 'endpoint_intune_linked')->exists())->toBeTrue();

    // Duplicate UUID on a second computer returns clean 422 validation error.
    $this->actingAs($manager)
        ->postJson("/security-devices/devices/{$otherComputer->id}/endpoint/intune-link", [
            'managed_device_id' => $uuidOne,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['managed_device_id']);

    // Simulate a synced snapshot; re-saving same UUID preserves snapshot, changing UUID resets to pending.
    $link->update([
        'snapshot' => ['id' => $uuidOne, 'complianceState' => 'compliant', 'isEncrypted' => true],
        'sync_status' => 'current',
        'synced_at' => now(),
    ]);

    $this->actingAs($manager)
        ->postJson("/security-devices/devices/{$computer->id}/endpoint/intune-link", [
            'managed_device_id' => $uuidOne,
        ])
        ->assertOk();
    expect($link->fresh()->sync_status)->toBe('current')
        ->and($link->fresh()->snapshot['complianceState'])->toBe('compliant');

    $this->actingAs($manager)
        ->postJson("/security-devices/devices/{$computer->id}/endpoint/intune-link", [
            'managed_device_id' => $uuidTwo,
            'reason' => 'Computer re-enrolled in Microsoft Intune.',
        ])
        ->assertOk();
    expect($link->fresh()->managed_device_id)->toBe($uuidTwo)
        ->and($link->fresh()->sync_status)->toBe('pending')
        ->and($link->fresh()->snapshot)->toBeNull();

    // Unlink requires a >=10 character reason and logs endpoint_intune_unlinked.
    $this->actingAs($manager)
        ->deleteJson("/security-devices/devices/{$computer->id}/endpoint/intune-link", [
            'reason' => 'too short',
        ])
        ->assertUnprocessable();

    $this->actingAs($manager)
        ->deleteJson("/security-devices/devices/{$computer->id}/endpoint/intune-link", [
            'reason' => 'Computer retired from Microsoft Intune visibility.',
        ])
        ->assertOk();

    expect(IntuneDeviceLink::query()->where('device_id', $computer->id)->exists())->toBeFalse()
        ->and(DeviceEvent::query()->where('device_id', $computer->id)->where('event_type', 'endpoint_intune_unlinked')->exists())->toBeTrue();
});

it('surfaces non-diagnostic agent badges in Network & IT and Remote Support without leaking inventory', function () {
    $computer = endpointPostureComputer($this->site);
    endpointPostureAgent($computer, $this->site, $this->admin);

    $netResponse = $this->actingAs($this->admin)
        ->get('/security-devices/network-it')
        ->assertOk()
        ->assertInertia(function ($page) use ($computer): void {
            $devices = collect($page->toArray()['props']['networkItWorkspace']['activeTab']['devices']);
            $row = $devices->firstWhere('id', $computer->id);

            expect($row)->not->toBeNull()
                ->and($row['endpointAgent']['state'])->toBe('fresh')
                ->and($row['endpointAgent']['agentVersion'])->toBe('1.2.0');
        });
    $netResponse->assertDontSee('KAURI-SECRET-HOSTNAME-01');
    $netResponse->assertDontSee('Confidential Diagnostic Tool');

    $rsResponse = $this->actingAs($this->admin)
        ->get("/it/remote-support?device={$computer->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('computers.data.0.endpoint_agent.state', 'fresh')
            ->where('selected.endpoint_agent.state', 'fresh')
            ->where('availability.remote_support', false)
            ->where('availability.script_execution', false));
    $rsResponse->assertDontSee('KAURI-SECRET-HOSTNAME-01');
    $rsResponse->assertDontSee('Confidential Diagnostic Tool');
});

it('keeps new endpoint posture and Intune link files free of multi-tenant identifiers', function () {
    $files = [
        base_path('app/Domain/SecurityDevices/Endpoints/Presenters/EndpointDevicePosturePresenter.php'),
        base_path('app/Domain/SecurityDevices/Endpoints/Http/Controllers/EndpointIntuneLinkController.php'),
        base_path('resources/js/pages/security-devices/devices/device-endpoint-section.tsx'),
    ];

    foreach ($files as $file) {
        $contents = file_get_contents($file);
        expect($contents)->not->toMatch('/\b(tenant_id|tenantId|forTenant|organization_id|organisation_id)\b/');
    }
});
