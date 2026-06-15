<?php

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->seed(SeedHrPermissionsSeeder::class);

    // hr.surveys.* are in SeedHrPermissionsSeeder → the hr role gets them.
    $this->hr = User::factory()->create([
        'organization_id' => 1,
        'role' => 'hr',
        'approved_at' => now(),
    ]);
    $this->hr->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'hr')->first()->id,
    ]);
});

test('the standalone surveys index redirects to the wellbeing hub', function () {
    $this->actingAs($this->hr)
        ->get('/hr/surveys')
        ->assertRedirect('/hr/wellbeing');
});

test('the surveys create page redirects to the wellbeing hub', function () {
    $this->actingAs($this->hr)
        ->get('/hr/surveys/create')
        ->assertRedirect('/hr/wellbeing');
});

test('a specific retired survey route redirects to the wellbeing hub', function () {
    // The route is a static Route::redirect, so it resolves without a real row
    // (the HrSurvey model + tables were dropped once the system was fully retired).
    $this->actingAs($this->hr)
        ->get('/hr/surveys/999')
        ->assertRedirect('/hr/wellbeing');
});

test('the surveys route names still resolve (preserved, not deleted)', function () {
    expect(route('hr.surveys.index', [], false))->toBe('/hr/surveys');
    expect(route('hr.surveys.create', [], false))->toBe('/hr/surveys/create');
});
