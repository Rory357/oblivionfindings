<?php

use App\Models\Client;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\Timesheet;
use App\Models\User;
use App\Services\ShiftOperationalSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class);

it('keeps historical site context after a client moves locations', function () {
    $originalSite = Site::factory()->create(['name' => 'Kauri House']);
    $newSite = Site::factory()->create(['name' => 'Rata House']);
    $serviceContext = ServiceContext::factory()->create(['name' => 'Supported Living']);
    $staff = User::factory()->create(['name' => 'Morgan Smith']);
    $client = Client::factory()->create([
        'first_name' => 'Casey',
        'last_name' => 'Jones',
        'site_id' => $originalSite->id,
        'service_context_id' => $serviceContext->id,
    ]);

    $shift = Shift::factory()->create([
        'client_id' => $client->id,
        'site_id' => $originalSite->id,
        'service_context_id' => $serviceContext->id,
        'user_id' => $staff->id,
        'location' => 'Kauri House Lounge',
        'shift_type' => 'standard',
    ]);

    $timesheet = Timesheet::factory()->create([
        'user_id' => $staff->id,
        'client_id' => $client->id,
        'shift_id' => $shift->id,
    ]);

    $service = new ShiftOperationalSnapshotService;
    $timesheet->forceFill($service->snapshotForTimesheet($timesheet))->save();

    // Move the client to a new site after the snapshot is captured.
    $client->forceFill([
        'site_id' => $newSite->id,
    ])->save();

    $timesheet->refresh();

    expect($timesheet->shift_site_name_snapshot)->toBe('Kauri House')
        ->and($timesheet->client_name_snapshot)->toBe('Casey Jones')
        ->and($timesheet->shift_location_snapshot)->toBe('Kauri House Lounge');
});

it('keeps the original worker snapshot after a shift is reassigned later', function () {
    $site = Site::factory()->create(['name' => 'Totara House']);
    $serviceContext = ServiceContext::factory()->create(['name' => 'Residential Support']);
    $originalWorker = User::factory()->create(['name' => 'Morgan Smith']);
    $replacementWorker = User::factory()->create(['name' => 'Jordan Lee']);
    $client = Client::factory()->create([
        'first_name' => 'Jamie',
        'last_name' => 'Carter',
        'site_id' => $site->id,
        'service_context_id' => $serviceContext->id,
    ]);

    $shift = Shift::factory()->create([
        'client_id' => $client->id,
        'site_id' => $site->id,
        'service_context_id' => $serviceContext->id,
        'user_id' => $originalWorker->id,
        'location' => 'Totara House',
        'shift_type' => 'standard',
    ]);

    $timesheet = Timesheet::factory()->create([
        'user_id' => $originalWorker->id,
        'client_id' => $client->id,
        'shift_id' => $shift->id,
    ]);

    $service = new ShiftOperationalSnapshotService;
    $timesheet->forceFill($service->snapshotForTimesheet($timesheet))->save();

    // Reassign the shift to a different worker after the snapshot is captured.
    $shift->forceFill(['user_id' => $replacementWorker->id])->save();

    $timesheet->refresh();

    expect($timesheet->staff_name_snapshot)->toBe('Morgan Smith')
        ->and($timesheet->client_name_snapshot)->toBe('Jamie Carter')
        ->and($timesheet->shift_site_name_snapshot)->toBe('Totara House');
});
