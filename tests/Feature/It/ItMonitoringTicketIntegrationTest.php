<?php

use App\Domain\It\Services\ItTicketLinkService;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Models\ControlRoom\Device as ControlRoomDevice;
use App\Models\ControlRoomAlert;
use App\Models\ItTicket;
use Database\Seeders\SecurityDevicesSignalSeeder;

function monitoredDevice(string $domain = 'it_infrastructure'): Device
{
    $device = Device::factory()->create(['domain' => $domain]);

    ControlRoomDevice::create([
        'name' => $device->name,
        'canonical_device_id' => $device->id,
        'type' => ControlRoomDevice::TYPE_NETWORK,
    ]);

    return $device;
}

beforeEach(function () {
    config()->set('queue.default', 'sync');
    $this->seed(SecurityDevicesSignalSeeder::class);
});

it('links one ticket idempotently to a canonical device and alert', function () {
    $ticket = ItTicket::factory()->create(['tenant_id' => 1]);
    $device = Device::factory()->itInfrastructure()->create(['tenant_id' => 1]);
    $projection = ControlRoomDevice::create([
        'name' => $device->name,
        'canonical_device_id' => $device->id,
        'type' => ControlRoomDevice::TYPE_NETWORK,
    ]);
    $alert = ControlRoomAlert::factory()->create(['device_id' => $projection->id]);
    $service = app(ItTicketLinkService::class);

    $first = $service->link($ticket, $device, 'affected_device');
    $second = $service->link($ticket, $device, 'affected_device');
    $service->link($ticket, $alert, 'source_alert');

    expect($first->is($second))->toBeTrue()
        ->and($ticket->links()->count())->toBe(2)
        ->and($ticket->linked('affected_device')->firstOrFail()->linkable->is($device))->toBeTrue()
        ->and($ticket->linked('source_alert')->firstOrFail()->linkable->is($alert))->toBeTrue();
});

it('rejects a cross-tenant ticket link', function () {
    $ticket = ItTicket::factory()->create(['tenant_id' => 1]);
    $device = Device::factory()->create(['tenant_id' => 2]);

    expect(fn () => app(ItTicketLinkService::class)->link($ticket, $device, 'affected_device'))
        ->toThrow(DomainException::class, 'same tenant');
});

it('rejects a ticket link when the target tenant cannot be resolved', function () {
    $ticket = ItTicket::factory()->create(['tenant_id' => 1]);
    $alert = ControlRoomAlert::factory()->create([
        'site_id' => null,
        'device_id' => null,
    ]);

    expect(fn () => app(ItTicketLinkService::class)->link($ticket, $alert, 'source_alert'))
        ->toThrow(DomainException::class, 'tenant could not be resolved');
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
