<?php

use App\Domain\Hr\Models\HrDriverEligibility;
use App\Models\Client;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Services\Eligibility\Rules\DriverLicenceExpiryRule;
use App\Services\ShiftStaffEligibilityService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeDriverShift(Site $site, Client $client): Shift
{
    return Shift::factory()->create([
        'client_id' => $client->id,
        'site_id' => $site->id,
        'service_context_id' => ServiceContext::factory()->create()->id,
        'user_id' => User::factory(),
        'starts_at' => now()->addDay()->setTime(9, 0),
        'ends_at' => now()->addDay()->setTime(17, 0),
        'status' => 'scheduled',
        'coverage_roles' => ['driver'],
        'created_by' => User::factory(),
    ]);
}

function evaluateDriverLicenceDutyWindow(
    string $startsAt,
    string $endsAt,
    string $licenceExpiresOn,
): array {
    $timezone = (string) config('app.worker_timezone', 'Pacific/Auckland');
    $site = Site::factory()->create();
    $client = Client::factory()->create(['site_id' => $site->id]);
    $shift = makeDriverShift($site, $client);
    $shift->forceFill([
        'starts_at' => CarbonImmutable::parse($startsAt, $timezone)->utc(),
        'ends_at' => CarbonImmutable::parse($endsAt, $timezone)->utc(),
    ]);

    $staff = User::factory()->create();
    HrDriverEligibility::query()->create([
        'tenant_id' => 1,
        'user_id' => $staff->id,
        'licence_number' => 'BOUNDARY-001',
        'licence_class' => '2',
        'licence_expires_at' => $licenceExpiresOn,
        'status' => 'eligible',
        'can_drive_clients' => true,
    ]);

    return app(DriverLicenceExpiryRule::class)->evaluate($shift, $staff);
}

test('a driver licence remains valid throughout duty on its worker-local expiry date', function () {
    config(['app.worker_timezone' => 'Pacific/Auckland']);

    $result = evaluateDriverLicenceDutyWindow(
        '2026-08-31 13:00:00',
        '2026-08-31 17:00:00',
        '2026-08-31',
    );

    expect($result['passed'])->toBeFalse()
        ->and($result['severity'])->toBe('warning')
        ->and($result['message'])->toContain('expires on 31 Aug 2026');
});

test('a driver licence blocks duty that continues beyond its worker-local expiry date', function () {
    config(['app.worker_timezone' => 'Pacific/Auckland']);

    $result = evaluateDriverLicenceDutyWindow(
        '2026-08-31 09:00:00',
        '2026-09-01 00:00:01',
        '2026-08-31',
    );

    expect($result['passed'])->toBeFalse()
        ->and($result['severity'])->toBe('block')
        ->and($result['overrideable'])->toBeFalse()
        ->and($result['message'])->toContain('does not remain valid for this entire shift');
});

test('a driver licence covers a half-open duty window ending exactly at local midnight', function () {
    config(['app.worker_timezone' => 'Pacific/Auckland']);

    $result = evaluateDriverLicenceDutyWindow(
        '2026-08-31 16:00:00',
        '2026-09-01 00:00:00',
        '2026-08-31',
    );

    expect($result['passed'])->toBeFalse()
        ->and($result['severity'])->toBe('warning')
        ->and($result['message'])->toContain('expires on 31 Aug 2026');
});

test('the driver licence warning window follows worker-local calendar days', function () {
    config(['app.worker_timezone' => 'Pacific/Auckland']);

    $result = evaluateDriverLicenceDutyWindow(
        '2026-08-31 09:00:00',
        '2026-08-31 17:00:00',
        '2026-09-30',
    );

    expect($result['passed'])->toBeFalse()
        ->and($result['severity'])->toBe('warning')
        ->and($result['message'])->toContain('within 30 days');
});

test('driver-relevant eligibility fails closed when the planned duty window is invalid', function () {
    config(['app.worker_timezone' => 'Pacific/Auckland']);
    $timezone = (string) config('app.worker_timezone');
    $site = Site::factory()->create();
    $client = Client::factory()->create(['site_id' => $site->id]);
    $shift = makeDriverShift($site, $client);
    $shift->forceFill([
        'starts_at' => CarbonImmutable::parse('2026-08-31 17:00:00', $timezone)->utc(),
        'ends_at' => CarbonImmutable::parse('2026-08-31 09:00:00', $timezone)->utc(),
    ]);
    $staff = User::factory()->create();
    HrDriverEligibility::query()->create([
        'tenant_id' => 1,
        'user_id' => $staff->id,
        'licence_number' => 'BOUNDARY-INVALID',
        'licence_class' => '2',
        'licence_expires_at' => '2026-09-30',
        'status' => 'eligible',
        'can_drive_clients' => true,
    ]);

    $result = app(DriverLicenceExpiryRule::class)->evaluate($shift, $staff);

    expect($result['passed'])->toBeFalse()
        ->and($result['severity'])->toBe('block')
        ->and($result['message'])->toContain('planned shift duty window is invalid');
});

test('driver-relevant eligibility fails closed on a missing duty endpoint even without a recorded expiry', function () {
    $site = Site::factory()->create();
    $client = Client::factory()->create(['site_id' => $site->id]);
    $shift = makeDriverShift($site, $client);
    $shift->forceFill(['ends_at' => null]);
    $staff = User::factory()->create();
    HrDriverEligibility::query()->create([
        'tenant_id' => 1,
        'user_id' => $staff->id,
        'licence_number' => 'BOUNDARY-MISSING',
        'licence_class' => '2',
        'licence_expires_at' => null,
        'status' => 'eligible',
        'can_drive_clients' => true,
    ]);

    $result = app(DriverLicenceExpiryRule::class)->evaluate($shift, $staff);

    expect($result['passed'])->toBeFalse()
        ->and($result['severity'])->toBe('block')
        ->and($result['overrideable'])->toBeFalse()
        ->and($result['message'])->toContain('planned shift duty window is invalid');
});

test('a valid driver duty window preserves the overrideable missing-expiry warning', function () {
    $site = Site::factory()->create();
    $client = Client::factory()->create(['site_id' => $site->id]);
    $shift = makeDriverShift($site, $client);
    $staff = User::factory()->create();
    HrDriverEligibility::query()->create([
        'tenant_id' => 1,
        'user_id' => $staff->id,
        'licence_number' => 'BOUNDARY-NO-EXPIRY',
        'licence_class' => '2',
        'licence_expires_at' => null,
        'status' => 'eligible',
        'can_drive_clients' => true,
    ]);

    $result = app(DriverLicenceExpiryRule::class)->evaluate($shift, $staff);

    expect($result)->toMatchArray([
        'rule' => 'driver_licence',
        'passed' => false,
        'severity' => 'warning',
        'overrideable' => true,
        'message' => 'Driving licence expiry date not recorded.',
    ]);
});

test('a far-future driver licence does not spuriously warn about expiry', function () {
    $site = Site::factory()->create();
    $client = Client::factory()->create(['site_id' => $site->id]);
    $shift = makeDriverShift($site, $client);

    $staff = User::factory()->create();
    HrDriverEligibility::query()->create([
        'tenant_id' => 1,
        'user_id' => $staff->id,
        'licence_number' => 'AB123456',
        'licence_class' => '2',
        'licence_expires_at' => now()->addYears(3)->toDateString(),
        'status' => 'eligible',
        'can_drive_clients' => true,
    ]);

    $result = app(ShiftStaffEligibilityService::class)->evaluate($shift, $staff)->toArray();

    expect(collect($result['warning_reasons'])->implode(' '))->not->toContain('Driving licence expires');
});

test('a driver licence expiring within the warning window warns', function () {
    $site = Site::factory()->create();
    $client = Client::factory()->create(['site_id' => $site->id]);
    $shift = makeDriverShift($site, $client);

    $staff = User::factory()->create();
    HrDriverEligibility::query()->create([
        'tenant_id' => 1,
        'user_id' => $staff->id,
        'licence_number' => 'AB123456',
        'licence_class' => '2',
        'licence_expires_at' => now()->addDays(10)->toDateString(),
        'status' => 'eligible',
        'can_drive_clients' => true,
    ]);

    $result = app(ShiftStaffEligibilityService::class)->evaluate($shift, $staff)->toArray();

    expect(collect($result['warning_reasons'])->implode(' '))->toContain('Driving licence expires');
});
