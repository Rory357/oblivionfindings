<?php

use App\Domain\Hr\Models\HrPublicHoliday;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\HrPublicHolidaysSeeder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->seed(SeedHrPermissionsSeeder::class);
    $this->seed(HrPublicHolidaysSeeder::class);

    $this->hr = User::factory()->create([
        'role' => 'hr',
        'organization_id' => 1,
        'approved_at' => now(),
    ]);

    $this->hr->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'hr')->firstOrFail()->id,
    ]);
});

test('public holidays page lists seeded nz holidays for the selected year', function () {
    $response = $this->actingAs($this->hr)->get('/hr/leave/holidays?year=2026');

    $response->assertOk();

    $holidayNames = collect($response->inertiaProps('holidays'))->pluck('name')->all();
    expect($holidayNames)->toContain("New Year's Day")
        ->and($holidayNames)->toContain('Matariki');
});

test('hr leave managers can create edit and delete public holidays', function () {
    $this->actingAs($this->hr)
        ->post('/hr/leave/holidays', [
            'name' => 'Canterbury Anniversary Day',
            'date' => '2026-11-13',
            'region' => 'canterbury',
            'is_national' => false,
        ])
        ->assertRedirect('/hr/leave/holidays?year=2026');

    $holiday = HrPublicHoliday::query()
        ->where('name', 'Canterbury Anniversary Day')
        ->where('tenant_id', 1)
        ->firstOrFail();

    expect($holiday->tenant_id)->toBe(1)
        ->and($holiday->year)->toBe(2026)
        ->and($holiday->is_national)->toBeFalse();

    $this->actingAs($this->hr)
        ->put("/hr/leave/holidays/{$holiday->id}", [
            'name' => 'Canterbury Show Day',
            'date' => '2026-11-13',
            'region' => 'canterbury',
            'is_national' => false,
        ])
        ->assertRedirect('/hr/leave/holidays?year=2026');

    expect($holiday->fresh()->name)->toBe('Canterbury Show Day');

    $this->actingAs($this->hr)
        ->delete("/hr/leave/holidays/{$holiday->id}")
        ->assertRedirect('/hr/leave/holidays?year=2026');

    expect(HrPublicHoliday::query()->whereKey($holiday->id)->exists())->toBeFalse();
});

test('time off calendar includes public holidays for each day', function () {
    HrPublicHoliday::query()->updateOrCreate(
        [
            'tenant_id' => 1,
            'date' => '2026-07-10',
            'region' => 'national',
        ],
        [
            'name' => 'Matariki',
            'is_national' => true,
            'year' => 2026,
        ],
    );

    $response = $this->actingAs($this->hr)->get('/hr/calendar/time-off?month=2026-07');

    $response->assertOk();

    $matariki = collect($response->inertiaProps('calendarDays'))->firstWhere('date', '2026-07-10');

    $holidayNames = collect($matariki['public_holidays'])->pluck('name')->all();

    expect($holidayNames)->toContain('Matariki')
        ->and(collect($matariki['public_holidays'])->pluck('region')->all())->toContain('national');
});

test('production database seeding includes the durable public holidays seeder', function () {
    $databaseSeeder = file_get_contents(database_path('seeders/DatabaseSeeder.php'));

    expect($databaseSeeder)->toContain('HrPublicHolidaysSeeder::class');
});
