<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\SecurityDevices\Management\Data\CommandRequestInput;
use App\Domain\SecurityDevices\Management\Enums\CommandApprovalDecision;
use App\Domain\SecurityDevices\Management\Enums\CommandStatus;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandRequest;
use App\Domain\SecurityDevices\Management\Services\DeviceCommandRequestService;
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

function commandApprovalActor(string $role, ?Site $site = null): User
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

function commandApprovalGrant(User $actor, string $key): void
{
    $permission = Permission::query()->where('key', $key)->firstOrFail();
    $actor->permissionOverrides()->syncWithoutDetaching([
        $permission->id => ['allowed' => true],
    ]);
    $actor->unsetRelation('permissionOverrides')->unsetRelation('roles');
}

function pendingDoorCommand(Site $site, User $requester): DeviceCommandRequest
{
    $device = Device::factory()->security()->create([
        'category' => 'access_control',
        'subcategory' => 'door_controller',
        'provider' => 'contract-test',
        'config' => ['management' => ['capabilities' => ['access.door.unlock_timed']]],
    ]);
    DeviceAssignment::query()->create([
        'device_id' => $device->id,
        'assignable_type' => DeviceAssignment::TARGET_SITE,
        'assignable_id' => $site->id,
        'assigned_at' => now(),
    ]);

    return app(DeviceCommandRequestService::class)->request($device, $requester, new CommandRequestInput(
        capability: 'access.door.unlock_timed',
        parameters: ['duration_seconds' => 15],
        reason: 'Let the approved technician through the service entrance.',
        idempotencyKey: 'approval-door-'.$device->id.'-'.$requester->id,
        stepUpConfirmedAt: CarbonImmutable::now('UTC')->startOfSecond(),
        impactAcknowledged: true,
    ));
}

function pendingFirmwareCommand(Site $site, User $requester): DeviceCommandRequest
{
    $device = Device::factory()->create([
        'domain' => 'it_infrastructure',
        'category' => 'networking',
        'subcategory' => 'gateway',
        'provider' => 'contract-test',
        'config' => ['management' => ['capabilities' => ['firmware.update']]],
    ]);
    DeviceAssignment::query()->create([
        'device_id' => $device->id,
        'assignable_type' => DeviceAssignment::TARGET_SITE,
        'assignable_id' => $site->id,
        'assigned_at' => now(),
    ]);
    $change = ItChange::factory()->standard()->create([
        'maintenance_starts_at' => now()->subMinute(),
        'maintenance_ends_at' => now()->addMinutes(10),
    ]);
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
        'created_by_user_id' => $requester->id,
    ]);

    return app(DeviceCommandRequestService::class)->request($device, $requester, new CommandRequestInput(
        capability: 'firmware.update',
        parameters: ['target_version' => '4.2.0'],
        reason: 'Apply the approved gateway firmware during its maintenance window.',
        idempotencyKey: 'approval-firmware-'.$device->id.'-'.$requester->id,
        stepUpConfirmedAt: CarbonImmutable::now('UTC')->startOfSecond(),
        itChangeId: $change->id,
        impactAcknowledged: true,
    ));
}

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->seed(SecurityDevicesPermissionsSeeder::class);
    config()->set('monitoring.signing.active_key_id', 'command-approval-test');
    config()->set('monitoring.signing.keys', [
        'command-approval-test' => base64_encode(str_repeat('A', SODIUM_CRYPTO_AUTH_KEYBYTES)),
    ]);
});

it('records an independent approval and moves the door command to ready', function () {
    $site = Site::factory()->create();
    $requester = commandApprovalActor('it_manager');
    $approver = commandApprovalActor('it_manager');
    $command = pendingDoorCommand($site, $requester);

    $this->actingAs($approver)
        ->post("/security-devices/commands/{$command->id}/decision", [
            'decision' => CommandApprovalDecision::Approved->value,
            'comment' => 'Technician identity and attendance window confirmed.',
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $command->refresh();
    expect($command->status)->toBe(CommandStatus::Ready)
        ->and($command->approved_by_user_id)->toBe($approver->id)
        ->and($command->approved_at)->not->toBeNull()
        ->and($command->approvals()->count())->toBe(1)
        ->and($command->approvals()->sole()->decision)->toBe(CommandApprovalDecision::Approved)
        ->and($command->auditEvents()->where('action', 'approved')->count())->toBe(1);
});

it('moves a change-governed command to ready only when both actors can inspect the current change', function () {
    $site = Site::factory()->create();
    $requester = commandApprovalActor('it_manager', $site);
    $approver = commandApprovalActor('it_manager', $site);
    $command = pendingFirmwareCommand($site, $requester);

    $this->actingAs($approver)
        ->post("/security-devices/commands/{$command->id}/decision", [
            'decision' => 'approved',
            'comment' => 'The approved change, Device, Site and active window all match.',
        ])
        ->assertRedirect()
        ->assertSessionDoesntHaveErrors();

    expect($command->fresh()->status)->toBe(CommandStatus::Ready)
        ->and($command->fresh()->it_change_id)->not->toBeNull();
});

it('rejects a change-governed decision when the reviewer lacks IT change access', function () {
    $site = Site::factory()->create();
    $requester = commandApprovalActor('it_manager', $site);
    $reviewer = commandApprovalActor('coordinator', $site);
    commandApprovalGrant($reviewer, 'securityDevices.commands.approve');
    $command = pendingFirmwareCommand($site, $requester);

    $this->actingAs($reviewer)
        ->post("/security-devices/commands/{$command->id}/decision", [
            'decision' => 'approved',
            'comment' => 'Attempt approval without permission to inspect the linked IT Change.',
        ])
        ->assertSessionHasErrors('decision');

    $this->actingAs($reviewer)
        ->get("/security-devices/devices/{$command->device_id}")
        ->assertOk()
        ->assertInertia(function ($page): void {
            $history = $page->toArray()['props']['profile']['management']['history'];

            expect($history)->toHaveCount(1)
                ->and($history[0]['change'])->toBeNull()
                ->and($history[0]['canDecide'])->toBeFalse();
        });

    expect($command->fresh()->status)->toBe(CommandStatus::AwaitingApproval)
        ->and($command->approvals()->count())->toBe(0);
});

it('omits a participant-visible command change unless the viewer also has the IT destination permission', function () {
    $site = Site::factory()->create();
    $requester = commandApprovalActor('it_manager', $site);
    $participant = commandApprovalActor('coordinator', $site);
    commandApprovalGrant($participant, 'securityDevices.commands.observe');
    $command = pendingFirmwareCommand($site, $requester);
    $command->change->ticket()->update([
        'requester_user_id' => $participant->id,
    ]);

    $this->actingAs($participant)
        ->get("/security-devices/devices/{$command->device_id}")
        ->assertOk()
        ->assertInertia(function ($page): void {
            $history = $page->toArray()['props']['profile']['management']['history'];

            expect($history)->toHaveCount(1)
                ->and($history[0]['change'])->toBeNull();
        });

    $this->actingAs($requester)
        ->get("/security-devices/devices/{$command->device_id}")
        ->assertOk()
        ->assertInertia(function ($page) use ($command): void {
            $change = $page->toArray()['props']['profile']['management']['history'][0]['change'];

            expect($change['id'])->toBe($command->it_change_id);
        });
});

it('prevents self approval and a second decision', function () {
    $site = Site::factory()->create();
    $requester = commandApprovalActor('it_manager');
    $approver = commandApprovalActor('it_manager');
    $command = pendingDoorCommand($site, $requester);
    $decision = [
        'decision' => 'approved',
        'comment' => 'Technician identity and attendance window confirmed.',
    ];

    $this->actingAs($requester)
        ->post("/security-devices/commands/{$command->id}/decision", $decision)
        ->assertSessionHasErrors('decision');
    expect($command->approvals()->count())->toBe(0);

    $this->actingAs($approver)
        ->post("/security-devices/commands/{$command->id}/decision", $decision)
        ->assertRedirect();
    $this->actingAs(commandApprovalActor('it_manager'))
        ->post("/security-devices/commands/{$command->id}/decision", $decision)
        ->assertSessionHasErrors('decision');

    expect($command->approvals()->count())->toBe(1);
});

it('records a rejection as terminal without making the command dispatchable', function () {
    $site = Site::factory()->create();
    $requester = commandApprovalActor('it_manager');
    $approver = commandApprovalActor('it_manager');
    $command = pendingDoorCommand($site, $requester);

    $this->actingAs($approver)
        ->post("/security-devices/commands/{$command->id}/decision", [
            'decision' => 'rejected',
            'comment' => 'The attendance window and named technician do not match.',
        ])
        ->assertRedirect();

    expect($command->fresh()->status)->toBe(CommandStatus::Rejected)
        ->and($command->fresh()->safe_failure_reason)->toBe('Rejected by an independent reviewer.')
        ->and($command->attempts()->count())->toBe(0);
});

it('conceals approval decisions from reviewers outside the command Site', function () {
    $allowedSite = Site::factory()->create();
    $hiddenSite = Site::factory()->create();
    $requester = commandApprovalActor('it_manager');
    $reviewer = commandApprovalActor('coordinator', $allowedSite);
    commandApprovalGrant($reviewer, 'securityDevices.commands.approve');
    $command = pendingDoorCommand($hiddenSite, $requester);

    $this->actingAs($reviewer)
        ->post("/security-devices/commands/{$command->id}/decision", [
            'decision' => 'approved',
            'comment' => 'Attempt to approve a command outside the reviewer Site.',
        ])
        ->assertNotFound();

    expect($command->approvals()->count())->toBe(0)
        ->and($command->fresh()->status)->toBe(CommandStatus::AwaitingApproval);
});

it('expires a command before a late approval and preserves the expiry audit', function () {
    $site = Site::factory()->create();
    $requester = commandApprovalActor('it_manager');
    $approver = commandApprovalActor('it_manager');
    $command = pendingDoorCommand($site, $requester);
    $this->travel(6)->minutes();

    $this->actingAs($approver)
        ->post("/security-devices/commands/{$command->id}/decision", [
            'decision' => 'approved',
            'comment' => 'This decision arrived after the safe execution window.',
        ])
        ->assertSessionHasErrors('decision');

    expect($command->fresh()->status)->toBe(CommandStatus::Expired)
        ->and($command->approvals()->count())->toBe(0)
        ->and($command->auditEvents()->where('action', 'expired_before_decision')->count())->toBe(1);
});
