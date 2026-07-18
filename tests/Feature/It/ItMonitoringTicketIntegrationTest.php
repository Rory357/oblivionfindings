<?php

use App\Domain\It\Services\ItTicketLinkService;
use App\Domain\SecurityDevices\Models\Device;
use App\Models\ControlRoom\Device as ControlRoomDevice;
use App\Models\ControlRoomAlert;
use App\Models\ItTicket;

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
