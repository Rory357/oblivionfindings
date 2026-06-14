<?php

use App\Domain\Hr\Models\HrSurvey;
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

test('a specific retired survey redirects to the wellbeing hub', function () {
    $survey = HrSurvey::query()->create([
        'tenant_id' => 1,
        'title' => 'Legacy pulse',
        'survey_type' => 'pulse',
        'status' => 'active',
        'created_by' => $this->hr->id,
    ]);

    $this->actingAs($this->hr)
        ->get("/hr/surveys/{$survey->id}")
        ->assertRedirect('/hr/wellbeing');
});

test('the surveys route names still resolve (preserved, not deleted)', function () {
    expect(route('hr.surveys.index', [], false))->toBe('/hr/surveys');
    expect(route('hr.surveys.create', [], false))->toBe('/hr/surveys/create');
});
