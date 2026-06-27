<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrSalaryBand;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->seed(SeedHrPermissionsSeeder::class);

    $this->hr = User::factory()->create([
        'organization_id' => 1,
        'role' => 'hr',
        'approved_at' => now(),
    ]);
    $this->hr->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'hr')->first()->id,
    ]);
});

function makeBandPlacementProfile(int $tenantId, string $role, ?float $annual): HrEmployeeProfile
{
    static $n = 0;
    $n++;

    return HrEmployeeProfile::query()->create([
        'tenant_id' => $tenantId,
        'user_id' => User::factory()->create(['organization_id' => 1, 'approved_at' => now()])->id,
        'employee_number' => 'EMP-BAND-'.$n,
        'work_email' => 'band'.$n.'@example.test',
        'position_title' => 'Support Worker',
        'position_role' => $role,
        'employment_type' => 'full_time',
        'annual_salary' => $annual,
        'start_date' => now()->subYear()->toDateString(),
        'is_active' => true,
    ]);
}

test('bands page exposes true compa-ratio aggregates and per-band placements', function () {
    HrSalaryBand::query()->create([
        'tenant_id' => 1,
        'created_by' => $this->hr->id,
        'position_role' => 'support_worker',
        'band_name' => 'Band A',
        'min_salary' => 50000,
        'mid_salary' => 60000,
        'max_salary' => 70000,
        'min_hourly' => 25,
        'max_hourly' => 35,
        'currency' => 'NZD',
        'effective_from' => now()->subMonth()->toDateString(),
    ]);

    makeBandPlacementProfile(1, 'support_worker', 60000); // in band, compa 1.0
    makeBandPlacementProfile(1, 'support_worker', 45000); // under band
    makeBandPlacementProfile(1, 'support_worker', 80000); // over band

    $this->actingAs($this->hr)
        ->get('/hr/compensation/bands')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('stats.bands_total', 1)
            ->where('stats.roles_covered', 1)
            ->where('stats.people_placed', 3)
            ->where('stats.people_out_of_band', 2)
            ->where('bands.data.0.employee_count', 3)
            ->where('bands.data.0.in_band', 1)
            ->where('bands.data.0.under_band', 1)
            ->where('bands.data.0.over_band', 1)
        );
});

test('storing a band with min greater than mid is rejected', function () {
    $this->actingAs($this->hr)
        ->from('/hr/compensation/bands')
        ->post('/hr/compensation/bands', [
            'position_role' => 'support_worker',
            'band_name' => 'Bad Band',
            'min_salary' => 70000,
            'mid_salary' => 60000,
            'max_salary' => 80000,
            'min_hourly' => 25,
            'max_hourly' => 35,
            'effective_from' => '2026-07-01',
        ])
        ->assertSessionHasErrors('mid_salary');

    $this->assertDatabaseMissing('hr_salary_bands', ['band_name' => 'Bad Band']);
});

test('updating a band cannot set max below the existing mid', function () {
    $band = HrSalaryBand::query()->create([
        'tenant_id' => 1,
        'created_by' => $this->hr->id,
        'position_role' => 'support_worker',
        'band_name' => 'Band A',
        'min_salary' => 50000,
        'mid_salary' => 60000,
        'max_salary' => 70000,
        'min_hourly' => 25,
        'max_hourly' => 35,
        'currency' => 'NZD',
        'effective_from' => now()->subMonth()->toDateString(),
    ]);

    $this->actingAs($this->hr)
        ->from('/hr/compensation/bands')
        ->put('/hr/compensation/bands/'.$band->id, [
            'max_salary' => 55000, // below the existing mid of 60000
        ])
        ->assertSessionHasErrors('max_salary');
});

test('partial update sending only effective_to before effective_from is rejected', function () {
    $band = HrSalaryBand::query()->create([
        'tenant_id' => 1,
        'created_by' => $this->hr->id,
        'position_role' => 'support_worker',
        'band_name' => 'Band A',
        'min_salary' => 50000,
        'mid_salary' => 60000,
        'max_salary' => 70000,
        'min_hourly' => 25,
        'max_hourly' => 35,
        'currency' => 'NZD',
        'effective_from' => '2026-07-01',
    ]);

    // Only effective_to in the payload — Laravel's after:effective_from rule can't
    // see the stored effective_from, so the controller must guard it manually.
    $this->actingAs($this->hr)
        ->from('/hr/compensation/bands')
        ->put('/hr/compensation/bands/'.$band->id, [
            'effective_to' => '2026-06-01', // before the stored 2026-07-01
        ])
        ->assertSessionHasErrors('effective_to');
});

test('export streams a salary-bands csv', function () {
    HrSalaryBand::query()->create([
        'tenant_id' => 1,
        'created_by' => $this->hr->id,
        'position_role' => 'support_worker',
        'band_name' => 'Band A',
        'min_salary' => 50000,
        'mid_salary' => 60000,
        'max_salary' => 70000,
        'min_hourly' => 25,
        'max_hourly' => 35,
        'currency' => 'NZD',
        'effective_from' => now()->subMonth()->toDateString(),
    ]);

    $response = $this->actingAs($this->hr)->get('/hr/compensation/bands/export');

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('text/csv');
});
