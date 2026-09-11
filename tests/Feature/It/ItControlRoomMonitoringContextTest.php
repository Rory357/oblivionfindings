<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\It\Presenters\ItTicketActivityPresenter;
use App\Domain\It\Presenters\ItTicketContextPresenter;
use App\Domain\It\Services\ItTicketLinkService;
use App\Domain\Monitoring\Models\MonitoringIncidentEvidenceSnapshot;
use App\Domain\Monitoring\Services\MonitoringTechnicalSummary;
use App\Domain\SecurityDevices\Enums\DeviceStatus;
use App\Domain\SecurityDevices\Enums\HealthStatus;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Models\ControlRoom\Device as ControlRoomDevice;
use App\Models\ControlRoomAlert;
use App\Models\ItTicket;
use App\Models\ItTicketEvent;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\ControlRoom\AlertWorkspaceService;
use Database\Seeders\SecurityDevicesSignalSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

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
    config()->set('inertia.ssr.enabled', false);
    Notification::fake();
    Http::preventStrayRequests();
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
        ->and(data_get($snapshot->fresh()->snapshot, 'observation.message'))->toBe(MonitoringTechnicalSummary::observation('offline'))
        ->and($ticket->description)->toBe(MonitoringTechnicalSummary::observation('offline'))
        ->and(json_encode($ticket->events()->pluck('payload')->all()))->not->toContain('private-sentinel')
        ->and(json_encode($snapshot->fresh()->snapshot))->not->toContain('must-never-cross-modules')
        ->and(json_encode($snapshot->fresh()->snapshot))->not->toContain('private-community')
        ->and($itContext['devices'][0]['name'])->toBe('Kauri Core Switch — replacement')
        ->and($itContext['alerts'][0]['severity'])->toBe('critical')
        ->and($itContext['incident_evidence'][0]['device']['name'])->toBe('Kauri Core Switch')
        ->and($itContext['incident_evidence'][0]['integrity'])->toBe('verified')
        ->and(data_get($controlRoom, 'linked_it_work.id'))->toBe($ticket->id)
        ->and(data_get($controlRoom, 'linked_it_work.status'))->toBe('in_progress')
        ->and(data_get($controlRoom, 'linked_it_work.assignee.name'))->toBe($assignee->name)
        ->and(data_get($controlRoom, 'linked_it_work.access.state'))->toBe('available')
        ->and(data_get($controlRoom, 'linked_device.id'))->toBe($device->id)
        ->and(data_get($controlRoom, 'linked_device.name'))->toBe('Kauri Core Switch — replacement')
        ->and(data_get($controlRoom, 'linked_device.href'))->toBe("/security-devices/devices/{$device->id}")
        ->and(data_get($controlRoom, 'linked_device.access.state'))->toBe('available')
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
    expect($itContext['devices'])->toHaveCount(1)
        ->and(data_get($itContext, 'devices.0.id'))->toBeNull()
        ->and(data_get($itContext, 'devices.0.name'))->toBeNull()
        ->and(data_get($itContext, 'devices.0.href'))->toBeNull()
        ->and(data_get($itContext, 'devices.0.access.state'))->toBe('restricted')
        ->and(data_get($itContext, 'devices.0.access.message'))->toBe('Security & Devices access is required to open this Device.')
        ->and($itContext['alerts'])->toHaveCount(1)
        ->and(data_get($itContext, 'alerts.0.id'))->toBeNull()
        ->and(data_get($itContext, 'alerts.0.reference'))->toBeNull()
        ->and(data_get($itContext, 'alerts.0.href'))->toBeNull()
        ->and(data_get($itContext, 'alerts.0.access.state'))->toBe('restricted')
        ->and(data_get($itContext, 'alerts.0.access.message'))->toBe('Control Room access is required to open this alert.')
        ->and($itContext['incident_evidence'])->toBe([]);

    $controlRoomOnly = monitoringContextViewer($site, [
        'controlRoom.viewAny',
        'controlRoom.alerts.view',
        'securityDevices.devices.view',
    ]);
    $controlRoom = app(AlertWorkspaceService::class)->build($controlRoomOnly, $alert->id);
    expect(data_get($controlRoom, 'linked_it_work.id'))->toBeNull()
        ->and(data_get($controlRoom, 'linked_it_work.reference'))->toBeNull()
        ->and(data_get($controlRoom, 'linked_it_work.href'))->toBeNull()
        ->and(data_get($controlRoom, 'linked_it_work.access.state'))->toBe('restricted')
        ->and(data_get($controlRoom, 'linked_it_work.access.message'))->toBe('IT workspace access is required to open this record.')
        ->and(data_get($controlRoom, 'linked_device.id'))->toBe($device->id)
        ->and(data_get($controlRoom, 'linked_device.access.state'))->toBe('available')
        ->and(data_get($controlRoom, 'monitoring_incident_evidence'))->toBeNull();

    $controlRoomWithoutDevice = monitoringContextViewer($site, [
        'controlRoom.viewAny',
        'controlRoom.alerts.view',
    ]);
    $deviceRestricted = app(AlertWorkspaceService::class)->build($controlRoomWithoutDevice, $alert->id);
    expect(data_get($deviceRestricted, 'linked_device.id'))->toBeNull()
        ->and(data_get($deviceRestricted, 'linked_device.name'))->toBeNull()
        ->and(data_get($deviceRestricted, 'linked_device.href'))->toBeNull()
        ->and(data_get($deviceRestricted, 'linked_device.access.state'))->toBe('restricted')
        ->and(data_get($deviceRestricted, 'linked_device.access.message'))->toBe('Security & Devices access is required to open this Device.');

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

test('automatic monitoring never copies free text diagnostics into IT descriptions activity or snapshots', function (mixed $message) {
    ['site' => $site, 'device' => $device] = monitoringContextDevice();
    $source = DeviceEvent::query()->create([
        'device_id' => $device->id,
        'event_type' => 'offline',
        'severity' => 'high',
        'source' => 'oblivion_monitoring',
        'occurred_at' => now(),
        'payload' => ['message' => $message],
    ]);

    $ticket = ItTicket::query()->sole();
    $viewer = monitoringContextViewer($site, ['it.view', 'it.manage']);
    $activity = app(ItTicketActivityPresenter::class)->present($ticket, $viewer);
    $snapshot = MonitoringIncidentEvidenceSnapshot::query()->sole();

    expect($source->fresh()->payload['message'])->toBe($message)
        ->and($ticket->description)->toBe(MonitoringTechnicalSummary::observation('offline'))
        ->and(json_encode([$activity, $snapshot->snapshot, $ticket->events()->pluck('payload')->all()]))
        ->not->toContain('diagnostic-private-sentinel');
})->with([
    'unlabelled secret and personal information' => ['diagnostic-private-sentinel; resident details; Bearer unlabelled-secret'],
    'embedded URL credentials' => ['https://name:diagnostic-private-sentinel@example.invalid/check?auth=opaque'],
    'multiline diagnostic' => ["Probe failed\nAuthorization: Bearer diagnostic-private-sentinel\nstack trace"],
    'structured provider diagnostic' => [['nested' => ['password' => 'diagnostic-private-sentinel']]],
]);

test('historical monitoring diagnostics stay protected without changing stored evidence or human authored descriptions', function () {
    ['site' => $site, 'device' => $device] = monitoringContextDevice();
    $raw = 'Historical diagnostic-private-sentinel without a recognisable credential label';
    $source = DeviceEvent::withoutEvents(fn () => DeviceEvent::query()->create([
        'device_id' => $device->id, 'event_type' => 'offline', 'severity' => 'high',
        'source' => 'oblivion_monitoring', 'occurred_at' => now(), 'payload' => ['message' => $raw],
    ]));
    $alert = ControlRoomAlert::factory()->create([
        'site_id' => $site->id,
        'context' => ['normalized_data' => ['canonical_device_id' => $device->id]],
    ]);
    $ticket = ItTicket::factory()->create([
        'source' => 'system', 'work_type' => 'incident', 'description' => $raw,
        'site_id' => $site->id, 'is_organisation_wide' => false,
    ]);
    app(ItTicketLinkService::class)->linkMonitoringEvidence($ticket, $device, $alert);
    $payload = [
        'message' => $raw, 'device_id' => $device->id, 'device_event_id' => $source->id,
        'alert_id' => $alert->id, 'unexpected_provider_blob' => $raw,
        'system_principal' => ItTicketLinkService::MONITORING_PRINCIPAL,
        'operation' => ItTicketLinkService::MONITORING_OPERATION,
    ];
    $created = ItTicketEvent::record($ticket, 'created_from_monitoring', null, $payload);
    ItTicketEvent::record($ticket, 'monitoring_evidence_added', null, $payload);
    ItTicketEvent::record($ticket, 'monitoring_recovered', null, $payload);
    $snapshotData = ['observation' => ['event_type' => 'offline', 'message' => $raw]];
    $snapshot = MonitoringIncidentEvidenceSnapshot::query()->create([
        'control_room_alert_id' => $alert->id, 'it_ticket_id' => $ticket->id,
        'device_id' => $device->id, 'device_event_id' => $source->id, 'site_id' => $site->id,
        'evidence_version' => 1, 'captured_at' => now(), 'snapshot' => $snapshotData,
        'checksum' => MonitoringIncidentEvidenceSnapshot::checksumFor($snapshotData),
    ]);
    $privileged = monitoringContextViewer($site, [
        'it.view', 'it.manage', 'controlRoom.viewAny', 'controlRoom.alerts.view', 'securityDevices.devices.view',
    ]);
    $restricted = monitoringContextViewer($site, ['it.view', 'it.manage']);

    foreach ([$privileged, $restricted] as $viewer) {
        $activity = app(ItTicketActivityPresenter::class)->present($ticket, $viewer);
        $hubEvent = app(ItTicketActivityPresenter::class)->presentEvent($created, $viewer);
        expect($activity)->toHaveCount(3)
            ->and(json_encode([$activity, $hubEvent]))->not->toContain('diagnostic-private-sentinel')
            ->and($hubEvent['payload'])->toBe(['message' => MonitoringTechnicalSummary::observation('offline')]);
        $this->actingAs($viewer)->get(route('it.tickets.show', $ticket))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('ticket.description', MonitoringTechnicalSummary::observation('offline')));
    }

    $context = app(ItTicketContextPresenter::class)->present($ticket, $privileged);
    expect(data_get($context, 'incident_evidence.0.observation.message'))->toBe(MonitoringTechnicalSummary::observation('offline'))
        ->and(data_get($context, 'incident_evidence.0.integrity'))->toBe('verified')
        ->and(app(ItTicketContextPresenter::class)->present($ticket, $restricted)['incident_evidence'])->toBe([])
        ->and($snapshot->fresh()->snapshot)->toEqual($snapshotData)
        ->and($snapshot->fresh()->hasValidChecksum())->toBeTrue()
        ->and($ticket->fresh()->description)->toBe($raw)
        ->and($created->fresh()->payload)->toEqual($payload);

    $ticket->update(['description' => 'Technician added a safe repair description.']);
    expect(MonitoringTechnicalSummary::ticketDescription($ticket->fresh()))->toBe('Technician added a safe repair description.');
    $ordinary = ItTicket::factory()->create(['source' => 'system', 'description' => 'Ordinary system work.']);
    expect(MonitoringTechnicalSummary::ticketDescription($ordinary))->toBe('Ordinary system work.');
});
