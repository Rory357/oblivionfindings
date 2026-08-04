<?php

use App\Domain\Hr\Models\HrDriverEligibility;
use App\Domain\Rostering\RosterPublishValidator;
use App\Models\Client;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Services\ShiftStaffEligibilityService;

/**
 * Seam S3 — Driver eligibility (HR) → Fleet/Rostering. A driver whose licence
 * has expired (or been suspended) must be a HARD-STOP when they sit on a shift
 * requiring the 'driver' coverage role: DriverLicenceExpiryRule computes the
 * block, and the rostering publish gate ENFORCES it
 * (RosterPublishValidator → can_publish = false). The existing
 * tests/Feature/Rostering/DriverLicenceEligibilityWarningTest only covers the
 * *warning* window; these prove the *block* + its enforcement at rostering.
 */
function hrSeamDriverShift(int $driverId): Shift
{
    $site = Site::factory()->create();
    $client = Client::factory()->create(['site_id' => $site->id]);

    return Shift::factory()->create([
        'client_id' => $client->id,
        'site_id' => $site->id,
        'service_context_id' => ServiceContext::factory()->create()->id,
        'user_id' => $driverId,
        'starts_at' => now()->addDay()->setTime(9, 0),
        'ends_at' => now()->addDay()->setTime(17, 0),
        'status' => 'scheduled',
        'coverage_roles' => ['driver'],
        'created_by' => User::factory(),
    ]);
}

test('S3 seam: an expired driving licence BLOCKS eligibility for a driver shift', function () {
    $driver = User::factory()->create();
    HrDriverEligibility::query()->create([
        'user_id' => $driver->id,
        'licence_number' => 'EXP-001',
        'licence_class' => '2',
        'licence_expires_at' => now()->subDay()->toDateString(), // expired yesterday
        'status' => 'eligible',
        'can_drive_clients' => true,
    ]);
    $shift = hrSeamDriverShift($driver->id);

    $result = app(ShiftStaffEligibilityService::class)->evaluate($shift, $driver)->toArray();

    expect(collect($result['blocked_reasons'] ?? [])->implode(' '))
        ->toContain('Driving licence expired');
});

test('S3 seam: the rostering publish gate HARD-STOPS a roster with an expired-licence driver', function () {
    $driver = User::factory()->create();
    HrDriverEligibility::query()->create([
        'user_id' => $driver->id,
        'licence_number' => 'EXP-002',
        'licence_class' => '2',
        'licence_expires_at' => now()->subMonth()->toDateString(),
        'status' => 'eligible',
        'can_drive_clients' => true,
    ]);
    $shift = hrSeamDriverShift($driver->id);

    $result = app(RosterPublishValidator::class)->validateProposedShifts(collect([$shift]));

    // A blocking eligibility issue makes the roster un-publishable — the hard-stop.
    expect($result['can_publish'])->toBeFalse();
    expect(collect($result['blocks'])->pluck('message')->implode(' '))
        ->toContain('Driving licence expired');
});

test('S3 seam: a current driving licence does NOT trip the driver hard-stop', function () {
    $driver = User::factory()->create();
    HrDriverEligibility::query()->create([
        'user_id' => $driver->id,
        'licence_number' => 'OK-001',
        'licence_class' => '2',
        'licence_expires_at' => now()->addYears(3)->toDateString(),
        'status' => 'eligible',
        'can_drive_clients' => true,
    ]);
    $shift = hrSeamDriverShift($driver->id);

    $result = app(ShiftStaffEligibilityService::class)->evaluate($shift, $driver)->toArray();

    $allReasons = collect(array_merge($result['blocked_reasons'] ?? [], $result['warning_reasons'] ?? []))
        ->implode(' ');
    expect($allReasons)->not->toContain('Driving licence');
});
