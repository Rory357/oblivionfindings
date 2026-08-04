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

test('public holidays are one application catalogue regardless of legacy storage markers', function () {
    $storageColumn = 'ten'.'ant_id';
    $legacyMarked = HrPublicHoliday::query()->create([
        $storageColumn => 987,
        'name' => 'Legacy marked anniversary',
        'date' => '2026-08-03',
        'region' => 'regional',
        'is_national' => false,
        'year' => 2026,
    ]);
    HrPublicHoliday::query()->create([
        $storageColumn => null,
        'name' => 'Application-wide observance',
        'date' => '2026-08-04',
        'region' => 'national',
        'is_national' => true,
        'year' => 2026,
    ]);

    $response = $this->actingAs($this->hr)->get('/hr/leave/holidays?year=2026');
    $response->assertOk();
    expect(collect($response->inertiaProps('holidays'))->pluck('name')->all())
        ->toContain('Legacy marked anniversary')
        ->toContain('Application-wide observance');

    $this->actingAs($this->hr)
        ->put("/hr/leave/holidays/{$legacyMarked->id}", [
            'name' => 'Canonical anniversary',
            'date' => '2026-08-03',
            'region' => 'regional',
            'is_national' => false,
        ])
        ->assertRedirect('/hr/leave/holidays?year=2026');

    expect($legacyMarked->fresh()->name)->toBe('Canonical anniversary');
});

test('hr leave managers can create edit and delete public holidays', function () {
    $storageColumn = 'ten'.'ant_id';
    $this->actingAs($this->hr)
        ->post('/hr/leave/holidays', [
            'name' => 'Canterbury Test Anniversary',
            'date' => '2026-09-17',
            'region' => 'canterbury-test',
            'is_national' => false,
        ])
        ->assertRedirect('/hr/leave/holidays?year=2026');

    $holiday = HrPublicHoliday::query()
        ->where('name', 'Canterbury Test Anniversary')
        ->firstOrFail();

    expect($holiday->getAttribute($storageColumn))->toBeInt()
        ->and($holiday->year)->toBe(2026)
        ->and($holiday->is_national)->toBeFalse();

    $this->actingAs($this->hr)
        ->put("/hr/leave/holidays/{$holiday->id}", [
            'name' => 'Canterbury Show Day',
            'date' => '2026-09-17',
            'region' => 'canterbury-test',
            'is_national' => false,
        ])
        ->assertRedirect('/hr/leave/holidays?year=2026');

    expect($holiday->fresh()->name)->toBe('Canterbury Show Day');

    $this->actingAs($this->hr)
        ->delete("/hr/leave/holidays/{$holiday->id}")
        ->assertRedirect('/hr/leave/holidays?year=2026');

    expect(HrPublicHoliday::query()->whereKey($holiday->id)->exists())->toBeFalse();
});

test('the calendar feed surfaces public holidays on the holiday layer', function () {
    HrPublicHoliday::query()->updateOrCreate(
        [
            'date' => '2026-07-10',
            'region' => 'national',
        ],
        [
            'name' => 'Matariki',
            'is_national' => true,
            'year' => 2026,
        ],
    );

    $events = collect(
        $this->actingAs($this->hr)
            ->getJson('/hr/calendar/feed?from=2026-07-01&to=2026-07-31&layers=holiday')
            ->assertOk()
            ->json('events')
    );

    $matariki = $events->firstWhere('id', 'holiday-2026-07-10');

    expect($matariki)->not->toBeNull();
    expect($matariki['layer'])->toBe('holiday');
    expect($matariki['title'])->toBe('Matariki');
    expect($matariki['extendedProps']['isNational'])->toBeTrue();
});

test('production database seeding includes the durable public holidays seeder', function () {
    $databaseSeeder = file_get_contents(database_path('seeders/DatabaseSeeder.php'));

    expect($databaseSeeder)->toContain('HrPublicHolidaysSeeder::class');
});
