<?php

use App\Domain\It\Services\ItTicketLinkService;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Models\ControlRoom\Device as ControlRoomDevice;
use App\Models\ControlRoomAlert;
use App\Models\ItTicket;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\SecurityDevicesSignalSeeder;

function monitoredDevice(string $domain = 'it_infrastructure'): Device
{
    $site = Site::factory()->create();
    $device = Device::factory()->create(['domain' => $domain]);
    $actor = User::factory()->create();

    DeviceAssignment::query()->create([
        'device_id' => $device->id,
        'assignable_type' => DeviceAssignment::TARGET_SITE,
        'assignable_id' => $site->id,
        'assignment_type' => 'permanent',
        'assigned_at' => now(),
        'assigned_by_user_id' => $actor->id,
    ]);

    ControlRoomDevice::create([
        'name' => $device->name,
        'canonical_device_id' => $device->id,
        'site_id' => $site->id,
        'type' => ControlRoomDevice::TYPE_NETWORK,
    ]);

    return $device;
}

beforeEach(function () {
    config()->set('queue.default', 'sync');
    $this->seed(SecurityDevicesSignalSeeder::class);
});

it('links one ticket idempotently to a canonical device and alert', function () {
    $site = Site::factory()->create();
    $actor = User::factory()->create();
    $ticket = ItTicket::factory()->create([
        'source' => 'system',
        'site_id' => $site->id,
        'is_organisation_wide' => false,
    ]);
    $device = Device::factory()->itInfrastructure()->create();
    DeviceAssignment::query()->create([
        'device_id' => $device->id,
        'assignable_type' => DeviceAssignment::TARGET_SITE,
        'assignable_id' => $site->id,
        'assignment_type' => 'permanent',
        'assigned_at' => now(),
        'assigned_by_user_id' => $actor->id,
    ]);
    $projection = ControlRoomDevice::create([
        'name' => $device->name,
        'canonical_device_id' => $device->id,
        'site_id' => $site->id,
        'type' => ControlRoomDevice::TYPE_NETWORK,
    ]);
    $alert = ControlRoomAlert::factory()->create([
        'device_id' => $projection->id,
        'site_id' => $site->id,
    ]);
    $service = app(ItTicketLinkService::class);

    $service->linkMonitoringEvidence($ticket, $device, $alert);
    $service->linkMonitoringEvidence($ticket, $device, $alert);

    expect($ticket->links()->count())->toBe(2)
        ->and($ticket->linked('affected_device')->firstOrFail()->linkable->is($device))->toBeTrue()
        ->and($ticket->linked('source_alert')->firstOrFail()->linkable->is($alert))->toBeTrue()
        ->and($ticket->links()->where('context->system_principal', ItTicketLinkService::MONITORING_PRINCIPAL)->count())->toBe(2);
});

it('rejects a human ticket link without a current responsible actor', function () {
    $ticket = ItTicket::factory()->create();
    $device = Device::factory()->create();

    expect(fn () => app(ItTicketLinkService::class)->link($ticket, $device, 'affected_device'))
        ->toThrow(DomainException::class, 'responsible actor');
});

it('rejects monitoring links when canonical device and alert site evidence is absent', function () {
    $site = Site::factory()->create();
    $ticket = ItTicket::factory()->create([
        'source' => 'system',
        'site_id' => $site->id,
        'is_organisation_wide' => false,
    ]);
    $device = Device::factory()->itInfrastructure()->create();
    $projection = ControlRoomDevice::create([
        'name' => $device->name,
        'canonical_device_id' => $device->id,
        'site_id' => $site->id,
        'type' => ControlRoomDevice::TYPE_NETWORK,
    ]);
    $alert = ControlRoomAlert::factory()->create([
        'site_id' => $site->id,
        'device_id' => $projection->id,
    ]);

    expect(fn () => app(ItTicketLinkService::class)->linkMonitoringEvidence($ticket, $device, $alert))
        ->toThrow(DomainException::class, 'do not agree');
});

it('allows a system incident without a human requester', function () {
    $ticket = ItTicket::factory()->create([
        'requester_user_id' => null,
        'source' => 'system',
        'work_type' => 'incident',
    ]);

    expect($ticket->requester)->toBeNull()
        ->and($ticket->work_type)->toBe('incident')
        ->and($ticket->impact)->toBe('individual')
        ->and($ticket->urgency)->toBe('normal');
});

it('creates one linked system incident for a confirmed IT infrastructure outage', function () {
    $device = monitoredDevice();

    DeviceEvent::create([
        'device_id' => $device->id,
        'event_type' => 'offline',
        'severity' => 'high',
        'source' => 'oblivion_monitoring',
        'occurred_at' => now(),
    ]);

    $ticket = ItTicket::sole();

    expect($ticket->source)->toBe('system')
        ->and($ticket->work_type)->toBe('incident')
        ->and($ticket->category)->toBe('network')
        ->and($ticket->requester_user_id)->toBeNull()
        ->and($ticket->links()->where('relationship', 'source_alert')->exists())->toBeTrue()
        ->and($ticket->links()->where('relationship', 'affected_device')->exists())->toBeTrue()
        ->and($ticket->events()->where('type', 'created_from_monitoring')->exists())->toBeTrue();
});

it('adds repeated evidence to the same ticket instead of creating a duplicate', function () {
    $device = monitoredDevice();

    foreach (['first failure', 'repeated failure'] as $message) {
        DeviceEvent::create([
            'device_id' => $device->id,
            'event_type' => 'offline',
            'severity' => 'high',
            'source' => 'oblivion_monitoring',
            'occurred_at' => now(),
            'payload' => ['message' => $message],
        ]);
    }

    expect(ItTicket::count())->toBe(1)
        ->and(ItTicket::firstOrFail()->events()->where('type', 'monitoring_evidence_added')->count())->toBe(1);
});

it('creates a fresh incident when an outage repeats after the earlier monitoring ticket was resolved', function () {
    $device = monitoredDevice();
    DeviceEvent::create([
        'device_id' => $device->id,
        'event_type' => 'offline',
        'severity' => 'high',
        'source' => 'oblivion_monitoring',
        'occurred_at' => now(),
    ]);
    $resolved = ItTicket::query()->sole();
    $resolved->update([
        'status' => 'resolved',
        'resolved_at' => now(),
        'status_reason' => 'fixed',
    ]);

    DeviceEvent::create([
        'device_id' => $device->id,
        'event_type' => 'offline',
        'severity' => 'high',
        'source' => 'oblivion_monitoring',
        'occurred_at' => now()->addMinute(),
    ]);

    $freshIncident = ItTicket::query()->where('id', '!=', $resolved->id)->sole();
    expect(ItTicket::query()->count())->toBe(2)
        ->and($resolved->fresh()->status)->toBe('resolved')
        ->and($freshIncident->status)->toBe('open')
        ->and($freshIncident->events()->where('type', 'created_from_monitoring')->exists())->toBeTrue();
});

it('marks the ticket monitoring recovered but leaves technician resolution open', function () {
    $device = monitoredDevice();

    foreach ([['offline', 'high'], ['online', 'info']] as [$eventType, $severity]) {
        DeviceEvent::create([
            'device_id' => $device->id,
            'event_type' => $eventType,
            'severity' => $severity,
            'source' => 'oblivion_monitoring',
            'occurred_at' => now(),
        ]);
    }

    $ticket = ItTicket::sole();

    expect($ticket->status)->toBe('open')
        ->and($ticket->status_reason)->toBe('monitoring_recovered')
        ->and($ticket->monitoring_recovered_at)->not->toBeNull()
        ->and($ticket->resolved_at)->toBeNull()
        ->and($ticket->closed_at)->toBeNull()
        ->and($ticket->events()->where('type', 'monitoring_recovered')->exists())->toBeTrue();
});

it('does not recover a system ticket that lacks the enforced monitoring principal evidence', function () {
    $device = monitoredDevice();
    $siteId = $device->assignments()->sole()->assignable_id;
    $ticket = ItTicket::factory()->create([
        'source' => 'system',
        'work_type' => 'incident',
        'site_id' => $siteId,
        'is_organisation_wide' => false,
        'status' => 'open',
        'status_reason' => 'monitoring_outage',
    ]);
    $ticket->links()->create([
        'tenant_id' => $ticket->tenant_id,
        'relationship' => 'affected_device',
        'linkable_type' => $device->getMorphClass(),
        'linkable_id' => $device->id,
        'context' => [],
    ]);

    DeviceEvent::create([
        'device_id' => $device->id,
        'event_type' => 'online',
        'severity' => 'info',
        'source' => 'oblivion_monitoring',
        'occurred_at' => now(),
    ]);

    expect($ticket->fresh()->status_reason)->toBe('monitoring_outage')
        ->and($ticket->fresh()->monitoring_recovered_at)->toBeNull()
        ->and($ticket->events()->where('type', 'monitoring_recovered')->exists())->toBeFalse();
});

it('does not turn security or healthcare signals into automatic IT incidents without policy', function (string $domain) {
    $device = monitoredDevice($domain);

    DeviceEvent::create([
        'device_id' => $device->id,
        'event_type' => 'offline',
        'severity' => 'high',
        'source' => 'oblivion_monitoring',
        'occurred_at' => now(),
    ]);

    expect(ItTicket::count())->toBe(0);
})->with(['security', 'iot_healthcare']);
