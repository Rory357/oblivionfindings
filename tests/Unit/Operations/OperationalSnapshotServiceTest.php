<?php

use App\Models\Client;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\Timesheet;
use App\Models\User;
use App\Services\ShiftOperationalSnapshotService;
use Carbon\Carbon;

uses(Tests\TestCase::class);

it('keeps historical site context after a client moves locations', function () {
    $originalSite = new Site(['id' => 10, 'name' => 'Kauri House']);
    $newSite = new Site(['id' => 11, 'name' => 'Rata House']);
    $serviceContext = new ServiceContext(['id' => 4, 'name' => 'Supported Living']);
    $staff = new User(['id' => 5, 'name' => 'Morgan Smith']);
    $client = new Client(['id' => 15, 'first_name' => 'Casey', 'last_name' => 'Jones', 'site_id' => 10, 'service_context_id' => 4]);
    $client->setRelation('site', $originalSite);
    $client->setRelation('serviceContext', $serviceContext);

    $shift = new Shift([
        'id' => 22,
        'client_id' => 15,
        'site_id' => 10,
        'service_context_id' => 4,
        'user_id' => 5,
        'location' => 'Kauri House Lounge',
        'shift_type' => 'standard',
    ]);
    $shift->setRelation('site', $originalSite);
    $shift->setRelation('client', $client);
    $shift->setRelation('serviceContext', $serviceContext);
    $shift->setRelation('staff', $staff);

    $timesheet = new Timesheet([
        'id' => 30,
        'user_id' => 5,
        'client_id' => 15,
        'shift_id' => 22,
        'starts_at' => Carbon::parse('2026-04-03 08:00:00'),
        'ends_at' => Carbon::parse('2026-04-03 16:00:00'),
        'break_minutes' => 30,
        'status' => 'approved',
    ]);
    $timesheet->setRelation('shift', $shift);
    $timesheet->setRelation('client', $client);
    $timesheet->setRelation('staff', $staff);

    $service = new ShiftOperationalSnapshotService();
    $snapshot = $service->snapshotForTimesheet($timesheet);

    $timesheet->forceFill($snapshot);

    $client->site_id = 11;
    $client->setRelation('site', $newSite);

    expect($timesheet->shift_site_name_snapshot)->toBe('Kauri House')
        ->and($timesheet->client_name_snapshot)->toBe('Casey Jones')
        ->and($timesheet->shift_location_snapshot)->toBe('Kauri House Lounge');
});

it('keeps the original worker snapshot after a shift is reassigned later', function () {
    $site = new Site(['id' => 10, 'name' => 'Totara House']);
    $serviceContext = new ServiceContext(['id' => 4, 'name' => 'Residential Support']);
    $originalWorker = new User(['id' => 5, 'name' => 'Morgan Smith']);
    $replacementWorker = new User(['id' => 9, 'name' => 'Jordan Lee']);
    $client = new Client(['id' => 15, 'first_name' => 'Jamie', 'last_name' => 'Carter', 'site_id' => 10, 'service_context_id' => 4]);
    $client->setRelation('site', $site);
    $client->setRelation('serviceContext', $serviceContext);

    $shift = new Shift([
        'id' => 22,
        'client_id' => 15,
        'site_id' => 10,
        'service_context_id' => 4,
        'user_id' => 5,
        'location' => 'Totara House',
        'shift_type' => 'standard',
    ]);
    $shift->setRelation('site', $site);
    $shift->setRelation('client', $client);
    $shift->setRelation('serviceContext', $serviceContext);
    $shift->setRelation('staff', $originalWorker);

    $timesheet = new Timesheet([
        'id' => 30,
        'user_id' => 5,
        'client_id' => 15,
        'shift_id' => 22,
        'starts_at' => Carbon::parse('2026-04-03 08:00:00'),
        'ends_at' => Carbon::parse('2026-04-03 16:00:00'),
        'break_minutes' => 30,
        'status' => 'approved',
    ]);
    $timesheet->setRelation('shift', $shift);
    $timesheet->setRelation('client', $client);
    $timesheet->setRelation('staff', $originalWorker);

    $service = new ShiftOperationalSnapshotService();
    $timesheet->forceFill($service->snapshotForTimesheet($timesheet));

    $shift->user_id = 9;
    $shift->setRelation('staff', $replacementWorker);

    expect($timesheet->staff_name_snapshot)->toBe('Morgan Smith')
        ->and($timesheet->client_name_snapshot)->toBe('Jamie Carter')
        ->and($timesheet->shift_site_name_snapshot)->toBe('Totara House');
});
