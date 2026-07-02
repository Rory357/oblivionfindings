<?php

use App\Domain\Hr\Models\HrDriverEligibility;
use App\Models\Client;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Services\ShiftStaffEligibilityService;
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
