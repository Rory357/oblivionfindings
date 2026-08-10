<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\SecurityDevices\Management\Data\CommandSigningPayload;
use App\Domain\SecurityDevices\Management\Enums\CommandStatus;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandIntakeAudit;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandRequest;
use App\Domain\SecurityDevices\Management\Services\CommandRequestSigner;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\ItChange;
use App\Models\ItTicketLink;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;

function commandRequestActor(string $role, ?Site $site = null): User
{
    $actor = User::factory()->create(['approved_at' => now()]);
    $actor->roles()->attach(Role::query()->where('name', $role)->firstOrFail());
    if ($site) {
        HrEmployeeProfile::factory()->create([
            'user_id' => $actor->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'is_active' => true,
        ]);
    }

    return $actor;
}

function commandRequestDevice(Site $site, array $attributes = []): Device
{
    $device = Device::factory()->security()->create(array_replace([
        'category' => 'access_control',
        'subcategory' => 'door_controller',
        'provider' => 'contract-test',
        'config' => [
            'management' => [
                'capabilities' => ['access.door.unlock_timed'],
            ],
        ],
    ], $attributes));
    DeviceAssignment::query()->create([
        'device_id' => $device->id,
        'assignable_type' => DeviceAssignment::TARGET_SITE,
        'assignable_id' => $site->id,
        'assigned_at' => now(),
    ]);

    return $device;
}

function grantCommandPermission(User $actor, string $key): void
{
    $permission = Permission::query()->where('key', $key)->firstOrFail();
    $actor->permissionOverrides()->syncWithoutDetaching([
        $permission->id => ['allowed' => true],
    ]);
    $actor->unsetRelation('permissionOverrides')->unsetRelation('roles');
}

function eligibleCommandChange(Site $site, Device $device, array $overrides = []): ItChange
{
    $change = ItChange::factory()->standard()->create(array_replace([
        'maintenance_starts_at' => now()->subMinute(),
        'maintenance_ends_at' => now()->addMinutes(10),
    ], $overrides));
    $change->ticket()->update([
        'site_id' => $site->id,
        'is_organisation_wide' => false,
        'work_type' => 'change',
        'workflow_state' => 'scheduled',
    ]);
    ItTicketLink::query()->create([
        'ticket_id' => $change->ticket_id,
        'relationship' => 'affected_device',
        'linkable_type' => $device->getMorphClass(),
        'linkable_id' => $device->id,
        'created_by_user_id' => $change->created_by_user_id,
    ]);

    return $change->refresh()->load('ticket');
}

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->seed(SecurityDevicesPermissionsSeeder::class);
    config()->set('monitoring.signing.active_key_id', 'command-request-test');
    config()->set('monitoring.signing.keys', [
        'command-request-test' => base64_encode(str_repeat('R', SODIUM_CRYPTO_AUTH_KEYBYTES)),
    ]);
});

it('creates a Site-scoped signed door command awaiting independent approval', function () {
    $site = Site::factory()->create();
    $actor = commandRequestActor('it_manager');
    $device = commandRequestDevice($site);
    $idempotencyKey = 'door-unlock-'.$device->id.'-first';

    $this->actingAs($actor)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post("/security-devices/devices/{$device->id}/commands", [
            'capability' => 'access.door.unlock_timed',
            'parameters' => ['duration_seconds' => 15],
            'reason' => 'Let the approved technician through the service entrance.',
            'idempotency_key' => $idempotencyKey,
            'impact_acknowledged' => true,
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $command = DeviceCommandRequest::query()->sole();
    expect($command->site_id)->toBe($site->id)
        ->and($command->requested_by_user_id)->toBe($actor->id)
        ->and($command->device_id)->toBe($device->id)
        ->and($command->status)->toBe(CommandStatus::AwaitingApproval)
        ->and($command->encrypted_parameters)->toBe(['duration_seconds' => 15])
        ->and($command->safe_parameter_summary)->toBe(['duration_seconds' => 15])
        ->and($command->confirmation_mode->value)->toBe('acknowledge_impact')
        ->and($command->impact_acknowledged_at)->not->toBeNull()
        ->and($command->auditEvents()->where('action', 'requested')->count())->toBe(1);

    $payload = new CommandSigningPayload(
        commandUuid: $command->command_uuid,
        deviceId: $command->device_id,
        siteId: $command->site_id,
        requestedByUserId: $command->requested_by_user_id,
        capability: $command->capability,
        capabilityVersion: $command->capability_version,
        managementLevel: $command->management_level->value,
        risk: $command->risk->value,
        idempotencyKey: $command->idempotency_key,
        parametersHash: app(CommandRequestSigner::class)->parametersHash($command->encrypted_parameters),
        reasonHash: app(CommandRequestSigner::class)->reasonHash($command->reason),
        expectedState: $command->expected_state,
        reconciliationRule: $command->reconciliation_rule,
        expiresAt: CarbonImmutable::instance($command->expires_at),
        itChangeId: $command->it_change_id,
        collectorId: $command->collector_id,
        isBreakGlass: $command->is_break_glass,
        provider: $command->provider,
        assignmentFingerprint: $command->assignment_fingerprint,
        confirmationMode: $command->confirmation_mode->value,
        impactAcknowledgedAt: CarbonImmutable::instance($command->impact_acknowledged_at),
    );
    expect($payload->toArray()['schema_version'])->toBe(6)
        ->and(app(CommandRequestSigner::class)->verify($payload, $command->signing_key_id, $command->signature))->toBeTrue();
});

it('records a high-risk request as awaiting step-up when confirmation is stale', function () {
    $site = Site::factory()->create();
    $actor = commandRequestActor('it_manager');
    $device = commandRequestDevice($site);

    $payload = [
        'capability' => 'access.door.unlock_timed',
        'parameters' => ['duration_seconds' => 10],
        'reason' => 'Allow the approved after-hours maintenance attendance.',
        'idempotency_key' => 'door-unlock-'.$device->id.'-stale-step-up',
        'impact_acknowledged' => true,
    ];
    $this->actingAs($actor)
        ->withSession(['auth.password_confirmed_at' => now()->subHour()->timestamp])
        ->post("/security-devices/devices/{$device->id}/commands", $payload)
        ->assertRedirect();

    expect(DeviceCommandRequest::query()->sole()->status)->toBe(CommandStatus::AwaitingStepUp);

    $this->actingAs($actor)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post("/security-devices/devices/{$device->id}/commands", $payload)
        ->assertRedirect();

    $command = DeviceCommandRequest::query()->sole();
    expect($command->status)->toBe(CommandStatus::AwaitingApproval)
        ->and($command->auditEvents()->count())->toBe(2);
});

it('preserves the exact Management destination through identity confirmation', function () {
    $site = Site::factory()->create();
    $actor = commandRequestActor('it_manager');
    $device = commandRequestDevice($site);

    $this->actingAs($actor)
        ->get("/security-devices/devices/{$device->id}/commands/confirm-identity")
        ->assertRedirect(route('password.confirm', absolute: false))
        ->assertSessionHas(
            'url.intended',
            "/security-devices/devices/{$device->id}?section=management",
        );
});

it('fails closed for unsupported capabilities and unallowlisted parameters', function () {
    $site = Site::factory()->create();
    $actor = commandRequestActor('it_manager');
    $device = commandRequestDevice($site);

    $this->actingAs($actor)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post("/security-devices/devices/{$device->id}/commands", [
            'capability' => 'access.door.lockdown',
            'parameters' => [],
            'reason' => 'Test the unsupported capability boundary safely.',
            'idempotency_key' => 'unsupported-'.$device->id,
        ])
        ->assertSessionHasErrors('capability');

    $this->actingAs($actor)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post("/security-devices/devices/{$device->id}/commands", [
            'capability' => 'access.door.unlock_timed',
            'parameters' => ['duration_seconds' => 15, 'raw_command' => 'UNLOCK ALL'],
            'reason' => 'Test the parameter allowlist boundary safely.',
            'idempotency_key' => 'unsafe-parameter-'.$device->id,
            'impact_acknowledged' => true,
        ])
        ->assertSessionHasErrors('parameters');

    $denied = DeviceCommandIntakeAudit::query()->where('outcome', 'denied')->get();
    expect(DeviceCommandRequest::query()->count())->toBe(0)
        ->and($denied)->toHaveCount(2)
        ->and($denied->pluck('safe_reason_code')->unique()->all())->toBe(['validation_failed'])
        ->and($denied->pluck('capability')->filter())->toBeEmpty();
});

it('requires fresh evidence for state changes while keeping observation-producing diagnostics available', function () {
    $site = Site::factory()->create();
    $actor = commandRequestActor('it_manager', $site);
    $actor->forceFill(['two_factor_confirmed_at' => null])->save();
    $device = commandRequestDevice($site, [
        'last_seen_at' => now()->subHour(),
        'config' => [
            'management' => [
                'capabilities' => ['access.door.unlock_timed', 'diagnostics.ping'],
            ],
        ],
    ]);

    $this->actingAs($actor)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post("/security-devices/devices/{$device->id}/commands", [
            'capability' => 'access.door.unlock_timed',
            'parameters' => ['duration_seconds' => 15],
            'reason' => 'Attempt a state change with evidence that is no longer current.',
            'idempotency_key' => 'stale-state-change-'.$device->id,
        ])
        ->assertSessionHasErrors('device');

    $this->actingAs($actor)
        ->post("/security-devices/devices/{$device->id}/commands", [
            'capability' => 'diagnostics.ping',
            'parameters' => [],
            'reason' => 'Collect a current reachability observation for the stale Device.',
            'idempotency_key' => 'stale-diagnostic-'.$device->id,
        ])
        ->assertRedirect()
        ->assertSessionDoesntHaveErrors();

    $command = DeviceCommandRequest::query()->sole();
    $denied = DeviceCommandIntakeAudit::query()->where('outcome', 'denied')->sole();
    $allowed = DeviceCommandIntakeAudit::query()->where('outcome', 'allowed')->sole();
    expect($command->capability)->toBe('diagnostics.ping')
        ->and($command->assignment_fingerprint)->toMatch('/^[a-f0-9]{64}$/')
        ->and($denied->safe_reason_code)->toBe('validation_failed')
        ->and($denied->capability)->toBeNull()
        ->and($denied->target_fingerprint)->toMatch('/^[a-f0-9]{64}$/')
        ->and($denied->capability_fingerprint)->toMatch('/^[a-f0-9]{64}$/')
        ->and(json_encode($denied->toArray()))->not->toContain('access.door.unlock_timed')
        ->and($allowed->device_command_request_id)->toBe($command->id)
        ->and($allowed->capability)->toBe('diagnostics.ping');
});

it('requires configured MFA for critical commands without collecting a password', function () {
    $site = Site::factory()->create();
    $actor = commandRequestActor('it_manager', $site);
    $actor->forceFill(['two_factor_confirmed_at' => null])->save();
    $device = commandRequestDevice($site, [
        'category' => 'cctv',
        'subcategory' => 'camera',
        'config' => ['management' => ['capabilities' => ['camera.privacy.disable']]],
    ]);
    $payload = [
        'capability' => 'camera.privacy.disable',
        'parameters' => [],
        'reason' => 'Disable privacy mode under the approved critical response procedure.',
        'idempotency_key' => 'critical-mfa-'.$device->id,
        'impact_acknowledged' => true,
        'confirmation_text' => $device->name,
    ];

    $this->actingAs($actor)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post("/security-devices/devices/{$device->id}/commands", $payload)
        ->assertSessionHasErrors('device');

    $actor->forceFill(['two_factor_confirmed_at' => now()])->save();
    $this->actingAs($actor->fresh())
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post("/security-devices/devices/{$device->id}/commands", $payload)
        ->assertRedirect()
        ->assertSessionDoesntHaveErrors();

    expect(DeviceCommandRequest::query()->sole()->status)->toBe(CommandStatus::AwaitingApproval)
        ->and(DeviceCommandIntakeAudit::query()->where('outcome', 'denied')->count())->toBe(1)
        ->and(DeviceCommandIntakeAudit::query()->where('outcome', 'allowed')->count())->toBe(1)
        ->and(array_keys($payload))->not->toContain('password');
});

it('requires server-side impact acknowledgement and exact Device confirmation for critical actions', function () {
    $site = Site::factory()->create();
    $actor = commandRequestActor('it_manager', $site);
    $actor->forceFill(['two_factor_confirmed_at' => now()])->save();
    $door = commandRequestDevice($site);

    $this->actingAs($actor)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post("/security-devices/devices/{$door->id}/commands", [
            'capability' => 'access.door.unlock_timed',
            'parameters' => ['duration_seconds' => 15],
            'reason' => 'Allow the approved technician through the service entrance.',
            'idempotency_key' => 'impact-required-'.$door->id,
        ])
        ->assertSessionHasErrors('impact_acknowledged');

    $camera = commandRequestDevice($site, [
        'name' => 'North Wing Privacy Camera',
        'category' => 'cctv',
        'subcategory' => 'camera',
        'config' => ['management' => ['capabilities' => ['camera.privacy.disable']]],
    ]);
    $critical = [
        'capability' => 'camera.privacy.disable',
        'parameters' => [],
        'reason' => 'Resume observation under the approved privacy and safety procedure.',
        'idempotency_key' => 'critical-confirmation-'.$camera->id,
        'impact_acknowledged' => true,
        'confirmation_text' => 'a different camera',
    ];

    $this->actingAs($actor->fresh())
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post("/security-devices/devices/{$camera->id}/commands", $critical)
        ->assertSessionHasErrors('confirmation_text');

    $this->actingAs($actor->fresh())
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post("/security-devices/devices/{$camera->id}/commands", array_replace($critical, [
            'confirmation_text' => $camera->name,
        ]))
        ->assertRedirect()
        ->assertSessionDoesntHaveErrors();

    $command = DeviceCommandRequest::query()->sole();
    expect($command->risk->value)->toBe('critical')
        ->and($command->confirmation_mode->value)->toBe('type_device_name')
        ->and($command->impact_acknowledged_at)->not->toBeNull()
        ->and($command->auditEvents()->where('action', 'requested')->sole()->safe_context)
        ->toMatchArray([
            'confirmation_mode' => 'type_device_name',
            'impact_acknowledged' => true,
        ]);
});

it('conceals a command target outside the actor approved Sites', function () {
    $allowedSite = Site::factory()->create();
    $hiddenSite = Site::factory()->create();
    $actor = commandRequestActor('coordinator', $allowedSite);
    grantCommandPermission($actor, 'securityDevices.commands.control');
    $hiddenDevice = commandRequestDevice($hiddenSite);

    $this->actingAs($actor)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post("/security-devices/devices/{$hiddenDevice->id}/commands", [
            'capability' => 'access.door.unlock_timed',
            'parameters' => ['duration_seconds' => 15],
            'reason' => 'Attempt a hidden Site target without revealing it.',
            'idempotency_key' => 'hidden-site-'.$hiddenDevice->id,
        ])
        ->assertNotFound();
    $this->get("/security-devices/devices/{$hiddenDevice->id}/commands/confirm-identity")
        ->assertNotFound();

    $denied = DeviceCommandIntakeAudit::query()->where('outcome', 'denied')->sole();
    expect(DeviceCommandRequest::query()->count())->toBe(0)
        ->and($denied->safe_reason_code)->toBe('target_not_found')
        ->and($denied->device_command_request_id)->toBeNull()
        ->and($denied->capability)->toBeNull()
        ->and($denied->target_fingerprint)->toMatch('/^[a-f0-9]{64}$/');
});

it('requires the exact capability permission and returns idempotent retries', function () {
    $site = Site::factory()->create();
    $supportWorker = commandRequestActor('support_worker', $site);
    $device = commandRequestDevice($site);
    $payload = [
        'capability' => 'access.door.unlock_timed',
        'parameters' => ['duration_seconds' => 15],
        'reason' => 'Allow the approved technician through the service entrance.',
        'idempotency_key' => 'door-unlock-'.$device->id.'-idempotent',
        'impact_acknowledged' => true,
    ];

    $this->actingAs($supportWorker)->post("/security-devices/devices/{$device->id}/commands", $payload)->assertForbidden();
    grantCommandPermission($supportWorker, 'securityDevices.commands.control');

    $this->actingAs($supportWorker)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post("/security-devices/devices/{$device->id}/commands", $payload)
        ->assertRedirect();
    $firstUuid = DeviceCommandRequest::query()->sole()->command_uuid;
    $this->actingAs($supportWorker)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post("/security-devices/devices/{$device->id}/commands", $payload)
        ->assertRedirect();

    expect(DeviceCommandRequest::query()->count())->toBe(1)
        ->and(DeviceCommandRequest::query()->sole()->command_uuid)->toBe($firstUuid);

    $this->actingAs($supportWorker)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post("/security-devices/devices/{$device->id}/commands", array_replace($payload, [
            'reason' => 'A materially different reason using the same idempotency key.',
        ]))
        ->assertSessionHasErrors('idempotency_key');
});

it('keeps incomplete break glass and irrelevant change links unavailable', function () {
    $site = Site::factory()->create();
    $actor = commandRequestActor('it_manager');
    $device = commandRequestDevice($site);
    $base = [
        'capability' => 'access.door.unlock_timed',
        'parameters' => ['duration_seconds' => 15],
        'reason' => 'Attempt a shortcut that must remain fail closed.',
        'impact_acknowledged' => true,
    ];

    $this->actingAs($actor)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post("/security-devices/devices/{$device->id}/commands", $base + [
            'idempotency_key' => 'break-glass-blocked-'.$device->id,
            'break_glass' => true,
            'break_glass_reason' => 'Emergency access is required for this test scenario.',
        ])
        ->assertSessionHasErrors('break_glass_reviewer_user_id');

    $this->actingAs($actor)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post("/security-devices/devices/{$device->id}/commands", $base + [
            'idempotency_key' => 'change-blocked-'.$device->id,
            'it_change_id' => 999999,
        ])
        ->assertSessionHasErrors('it_change_id');

    expect(DeviceCommandRequest::query()->count())->toBe(0);
});

it('binds a change-required action to the current Device Site and maintenance window', function () {
    $site = Site::factory()->create();
    $actor = commandRequestActor('it_manager', $site);
    $device = commandRequestDevice($site, [
        'domain' => 'it_infrastructure',
        'category' => 'networking',
        'subcategory' => 'gateway',
        'config' => ['management' => ['capabilities' => ['device.reboot']]],
    ]);
    $change = eligibleCommandChange($site, $device);

    $this->actingAs($actor)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post("/security-devices/devices/{$device->id}/commands", [
            'capability' => 'device.reboot',
            'parameters' => [],
            'reason' => 'Restart the gateway during the approved maintenance window.',
            'idempotency_key' => 'reboot-change-'.$device->id,
            'it_change_id' => $change->id,
            'impact_acknowledged' => true,
        ])
        ->assertRedirect()
        ->assertSessionDoesntHaveErrors();

    $command = DeviceCommandRequest::query()->sole();
    expect($command->status)->toBe(CommandStatus::AwaitingApproval)
        ->and($command->it_change_id)->toBe($change->id)
        ->and($command->expires_at->lessThanOrEqualTo($change->maintenance_ends_at))->toBeTrue();

    $otherDevice = commandRequestDevice($site, [
        'config' => ['management' => ['capabilities' => ['device.reboot']]],
    ]);
    $this->actingAs($actor)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post("/security-devices/devices/{$otherDevice->id}/commands", [
            'capability' => 'device.reboot',
            'parameters' => [],
            'reason' => 'Attempt to reuse a change linked to another Device.',
            'idempotency_key' => 'wrong-device-change-'.$otherDevice->id,
            'it_change_id' => $change->id,
            'impact_acknowledged' => true,
        ])
        ->assertSessionHasErrors('it_change_id');

    $futureChange = eligibleCommandChange($site, $device, [
        'maintenance_starts_at' => now()->addHour(),
        'maintenance_ends_at' => now()->addHours(2),
    ]);
    $this->actingAs($actor)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post("/security-devices/devices/{$device->id}/commands", [
            'capability' => 'device.reboot',
            'parameters' => [],
            'reason' => 'Attempt to run before the approved maintenance window.',
            'idempotency_key' => 'future-change-'.$device->id,
            'it_change_id' => $futureChange->id,
            'impact_acknowledged' => true,
        ])
        ->assertSessionHasErrors('it_change_id');

    expect(DeviceCommandRequest::query()->count())->toBe(1);
});
