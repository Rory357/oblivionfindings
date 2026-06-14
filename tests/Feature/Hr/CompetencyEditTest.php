<?php

use App\Domain\Hr\Models\HrCompetency;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->seed(SeedHrPermissionsSeeder::class);

    $this->hr = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $this->hr->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'hr')->first()->id,
    ]);
});

test('a competency can be edited via the now-wired update endpoint', function () {
    $competency = HrCompetency::query()->create([
        'tenant_id' => 1,
        'name' => 'Medication Administration',
        'description' => 'Original description.',
        'category' => 'Clinical',
        'proficiency_levels' => ['Beginner', 'Competent', 'Expert'],
        'is_active' => true,
        'sort_order' => 0,
    ]);

    $this->actingAs($this->hr)
        ->put("/hr/performance/competencies/{$competency->id}", [
            'name' => 'Medication Management',
            'category' => 'Clinical Skills',
            'proficiency_levels' => ['Novice', 'Developing', 'Competent', 'Advanced'],
        ])
        ->assertRedirect();

    $competency->refresh();
    expect($competency->name)->toBe('Medication Management');
    expect($competency->category)->toBe('Clinical Skills');
    expect($competency->proficiency_levels)->toHaveCount(4);
    expect($competency->proficiency_levels)->toContain('Advanced');
});

test('a competency can be created (the create path no longer 500s)', function () {
    $this->actingAs($this->hr)
        ->post('/hr/performance/competencies', [
            'name' => 'Active Listening',
            'category' => 'Behavioural',
            'proficiency_levels' => ['Low', 'Medium', 'High'],
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('hr_competencies', [
        'name' => 'Active Listening',
        'category' => 'Behavioural',
    ]);
});

test('the competency index renders for a manager', function () {
    HrCompetency::query()->create([
        'tenant_id' => 1,
        'name' => 'Teamwork',
        'category' => 'Behavioural',
        'proficiency_levels' => ['Low', 'High'],
        'is_active' => true,
        'sort_order' => 0,
    ]);

    $response = $this->actingAs($this->hr)->get('/hr/performance/competencies');
    $response->assertOk();

    expect(collect($response->inertiaProps('competencies')))->not->toBeEmpty();
});
