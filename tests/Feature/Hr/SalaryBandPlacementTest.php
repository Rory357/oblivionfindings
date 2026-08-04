<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrSalaryBand;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->seed(SeedHrPermissionsSeeder::class);
    $this->site = Site::factory()->create(['name' => 'Salary band placement Site']);

    $this->hr = User::factory()->create([
        'role' => 'hr',
        'approved_at' => now(),
    ]);
    $this->hr->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'hr')->first()->id,
    ]);
    HrEmployeeProfile::factory()->create([
        'user_id' => $this->hr->id,
        'primary_site_id' => $this->site->id,
        'position_role' => 'hr_manager',
        'is_active' => true,
    ]);
});

function makeBandPlacementProfile(string $role, ?float $annual): HrEmployeeProfile
{
    static $n = 0;
    $n++;

    return HrEmployeeProfile::query()->create([
        'user_id' => User::factory()->create(['approved_at' => now()])->id,
        'employee_number' => 'EMP-BAND-'.$n,
        'work_email' => 'band'.$n.'@example.test',
        'position_title' => 'Support Worker',
        'position_role' => $role,
        'primary_site_id' => Site::query()->value('id'),
        'employment_type' => 'full_time',
        'annual_salary' => $annual,
        'start_date' => now()->subYear()->toDateString(),
        'is_active' => true,
    ]);
}

test('bands page exposes true compa-ratio aggregates and per-band placements', function () {
    HrSalaryBand::query()->create([
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

    makeBandPlacementProfile('support_worker', 60000); // in band, compa 1.0
    makeBandPlacementProfile('support_worker', 45000); // under band
    makeBandPlacementProfile('support_worker', 80000); // over band

    $this->actingAs($this->hr)
        ->get('/hr/compensation/bands')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('stats.bands_total', 1)
            ->where('stats.roles_covered', 1)
            ->where('stats.people_placed', 3)
            ->where('stats.people_in_band', 1)
            ->where('stats.people_out_of_band', 2)
            ->where('stats.band_health', 33)
            ->has('stats.reviews_in_flight')
            ->has('stats.awaiting_approval')
            ->has('stats.reimbursed_this_month')
            ->where('tabCounts.bands', 1)
            ->where('bands.data.0.employee_count', 3)
            ->where('bands.data.0.in_band', 1)
            ->where('bands.data.0.under_band', 1)
            ->where('bands.data.0.over_band', 1)
        );
});

test('expenses index exposes the mileage rate + categories for the claim dialog', function () {
    $this->actingAs($this->hr)->get('/hr/compensation/expenses')->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('hr/compensation/expenses/index')
            ->has('mileageRatePerKm')
            ->has('categories')
            ->where('can.approve', true));
});

test('the pay-review builder receives active bands for placement', function () {
    HrSalaryBand::query()->create([
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

    // The builder now opens in place on the reviews list, which ships the bands.
    $this->actingAs($this->hr)->get('/hr/compensation/reviews')->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('hr/compensation/reviews')
            ->has('bands', 1));

    // The legacy create URL just redirects to the list now.
    $this->actingAs($this->hr)->get('/hr/compensation/reviews/create')
        ->assertRedirect('/hr/compensation/reviews');
});

test('the compensation history index and settings hub tabs render', function () {
    $this->actingAs($this->hr)->get('/hr/compensation/history')->assertOk()
        ->assertInertia(fn ($page) => $page->component('hr/compensation/history-index')->has('stats'));

    $this->actingAs($this->hr)->get('/hr/compensation/settings')->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('hr/compensation/settings')
            ->has('settings.mileage_rate_per_km')
            ->has('settings.gl_accounts'));
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
