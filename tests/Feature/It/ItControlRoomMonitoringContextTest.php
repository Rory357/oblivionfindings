<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\It\Presenters\ItTicketContextPresenter;
use App\Domain\Monitoring\Models\MonitoringIncidentEvidenceSnapshot;
use App\Domain\SecurityDevices\Enums\DeviceStatus;
use App\Domain\SecurityDevices\Enums\HealthStatus;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Models\ControlRoom\Device as ControlRoomDevice;
use App\Models\ControlRoomAlert;
use App\Models\ItTicket;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\ControlRoom\AlertWorkspaceService;
use Database\Seeders\SecurityDevicesSignalSeeder;
use Illuminate\Support\Facades\DB;

/** @param array<int, string> $permissionKeys */
function monitoringContextViewer(Site $site, array $permissionKeys): User
{
    $viewer = User::factory()->create(['approved_at' => now()]);
    $role = Role::query()->create([
        'name' => 'monitoring-context-'.str()->uuid(),
        'label' => 'Monitoring context test role',
        'level' => 60,
        'type' => 'custom',
    ]);

    foreach ($permissionKeys as $key) {
        $permission = Permission::query()->firstOrCreate(
            ['key' => $key],
            ['description' => $key, 'group' => 'it', 'module' => 'Operations'],
        );
        $role->permissions()->syncWithoutDetaching([$permission->id]);
    }
    $viewer->roles()->attach($role);

    HrEmployeeProfile::factory()->create([
        'user_id' => $viewer->id,
        'created_by' => $viewer->id,
        'updated_by' => $viewer->id,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        'is_active' => true,
        'start_date' => now()->subMonth()->toDateString(),
        'end_date' => null,
    ]);

    return $viewer;
}

/** @return array{site: Site, device: Device} */
function monitoringContextDevice(): array
{
    $site = Site::factory()->create(['name' => 'Kauri Network Site']);
    $device = Device::factory()->itInfrastructure()->create([
        'name' => 'Kauri Core Switch',
        'status' => DeviceStatus::Offline,
        'health_status' => HealthStatus::Critical,
    ]);
    $actor = User::factory()->create();

    DeviceAssignment::query()->create([
        'device_id' => $device->id,
        'assignable_type' => DeviceAssignment::TARGET_SITE,
        'assignable_id' => $site->id,
        'assignment_type' => 'permanent',
        'assigned_at' => now(),
        'assigned_by_user_id' => $actor->id,
    ]);

    ControlRoomDevice::query()->create([
        'name' => $device->name,
        'canonical_device_id' => $device->id,
        'site_id' => $site->id,
        'type' => ControlRoomDevice::TYPE_NETWORK,
    ]);

    return compact('site', 'device');
}

beforeEach(function () {
    config()->set('queue.default', 'sync');
    $this->seed(SecurityDevicesSignalSeeder::class);
});

test('one immutable incident snapshot preserves original evidence while both workspaces show live canonical state', function () {
    ['site' => $site, 'device' => $device] = monitoringContextDevice();
    $occurredAt = now()->subMinute()->startOfSecond();

    DeviceEvent::query()->create([
        'device_id' => $device->id,
        'event_type' => 'offline',
        'severity' => 'high',
        'source' => 'oblivion_monitoring',
        'occurred_at' => $occurredAt,
        'payload' => [
            'message' => 'WAN probe failed token=private-sentinel',
            'raw_provider_payload' => 'must-never-cross-modules',
            'configuration' => ['community' => 'private-community'],
        ],
    ]);

    $ticket = ItTicket::query()->sole();
    $alert = $ticket->linked('source_alert')->firstOrFail()->linkable;
    $snapshot = MonitoringIncidentEvidenceSnapshot::query()->sole();
    $originalChecksum = $snapshot->checksum;

    DeviceEvent::query()->create([
        'device_id' => $device->id,
        'event_type' => 'offline',
        'severity' => 'critical',
        'source' => 'oblivion_monitoring',
        'occurred_at' => now(),
        'payload' => ['message' => 'Later evidence must not rewrite the first snapshot.'],
    ]);

    $assignee = monitoringContextViewer($site, ['it.view', 'it.manage']);
    $device->update([
        'name' => 'Kauri Core Switch — replacement',
        'status' => DeviceStatus::Active,
        'health_status' => HealthStatus::Healthy,
    ]);
    $ticket->update([
        'status' => 'in_progress',
        'assigned_to_user_id' => $assignee->id,
    ]);
    $alert->update(['severity' => 'critical', 'status' => ControlRoomAlert::STATUS_TRIAGING]);

    $viewer = monitoringContextViewer($site, [
        'it.view',
        'controlRoom.viewAny',
        'controlRoom.alerts.view',
        'securityDevices.devices.view',
    ]);
    $itContext = app(ItTicketContextPresenter::class)->present($ticket->fresh(), $viewer);
    $controlRoom = app(AlertWorkspaceService::class)->build($viewer, $alert->id);

    expect(MonitoringIncidentEvidenceSnapshot::query()->count())->toBe(1)
        ->and(ItTicket::query()->count())->toBe(1)
        ->and($snapshot->fresh()->checksum)->toBe($originalChecksum)
        ->and($snapshot->fresh()->hasValidChecksum())->toBeTrue()
        ->and(data_get($snapshot->fresh()->snapshot, 'device.name'))->toBe('Kauri Core Switch')
        ->and(data_get($snapshot->fresh()->snapshot, 'observation.message'))->toBe('WAN probe failed token=[redacted]')
        ->and(json_encode($snapshot->fresh()->snapshot))->not->toContain('must-never-cross-modules')
        ->and(json_encode($snapshot->fresh()->snapshot))->not->toContain('private-community')
        ->and($itContext['devices'][0]['name'])->toBe('Kauri Core Switch — replacement')
        ->and($itContext['alerts'][0]['severity'])->toBe('critical')
        ->and($itContext['incident_evidence'][0]['device']['name'])->toBe('Kauri Core Switch')
        ->and($itContext['incident_evidence'][0]['integrity'])->toBe('verified')
        ->and(data_get($controlRoom, 'linked_it_work.id'))->toBe($ticket->id)
        ->and(data_get($controlRoom, 'linked_it_work.status'))->toBe('in_progress')
        ->and(data_get($controlRoom, 'linked_it_work.assignee.name'))->toBe($assignee->name)
        ->and(data_get($controlRoom, 'monitoring_incident_evidence.device.name'))->toBe('Kauri Core Switch')
        ->and(json_encode($controlRoom))->not->toContain('must-never-cross-modules');

    expect(fn () => $snapshot->fresh()->update(['checksum' => str_repeat('0', 64)]))
        ->toThrow(DomainException::class, 'immutable');
    expect(fn () => $snapshot->fresh()->delete())
        ->toThrow(DomainException::class, 'immutable');
});

test('source permissions Site access and checksum integrity fail closed on both projections', function () {
    ['site' => $site, 'device' => $device] = monitoringContextDevice();
    DeviceEvent::query()->create([
        'device_id' => $device->id,
        'event_type' => 'offline',
        'severity' => 'high',
        'source' => 'oblivion_monitoring',
        'occurred_at' => now(),
    ]);
    $ticket = ItTicket::query()->sole();
    $alert = $ticket->linked('source_alert')->firstOrFail()->linkable;
    $snapshot = MonitoringIncidentEvidenceSnapshot::query()->sole();

    $itOnly = monitoringContextViewer($site, ['it.view']);
    $itContext = app(ItTicketContextPresenter::class)->present($ticket, $itOnly);
    expect($itContext['devices'])->toBe([])
        ->and($itContext['alerts'])->toBe([])
        ->and($itContext['incident_evidence'])->toBe([]);

    $controlRoomOnly = monitoringContextViewer($site, [
        'controlRoom.viewAny',
        'controlRoom.alerts.view',
        'securityDevices.devices.view',
    ]);
    $controlRoom = app(AlertWorkspaceService::class)->build($controlRoomOnly, $alert->id);
    expect(data_get($controlRoom, 'linked_it_work'))->toBeNull()
        ->and(data_get($controlRoom, 'monitoring_incident_evidence'))->toBeNull();

    $otherSite = Site::factory()->create();
    $outsideViewer = monitoringContextViewer($otherSite, [
        'it.view',
        'controlRoom.viewAny',
        'controlRoom.alerts.view',
        'securityDevices.devices.view',
    ]);
    expect(app(AlertWorkspaceService::class)->build($outsideViewer, $alert->id))->toBeNull()
        ->and(app(ItTicketContextPresenter::class)->present($ticket, $outsideViewer)['incident_evidence'])->toBe([]);

    DB::table('monitoring_incident_evidence_snapshots')
        ->where('id', $snapshot->id)
        ->update(['snapshot' => json_encode(['tampered' => 'raw-secret-sentinel'])]);

    $allowed = monitoringContextViewer($site, [
        'it.view',
        'controlRoom.viewAny',
        'controlRoom.alerts.view',
        'securityDevices.devices.view',
    ]);
    expect(app(ItTicketContextPresenter::class)->present($ticket, $allowed)['incident_evidence'])->toBe([])
        ->and(data_get(app(AlertWorkspaceService::class)->build($allowed, $alert->id), 'monitoring_incident_evidence'))->toBeNull();
});
