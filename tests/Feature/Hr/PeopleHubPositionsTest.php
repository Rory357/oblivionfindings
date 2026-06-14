<?php

use App\Domain\Hr\Models\HrPosition;
use App\Models\Role;
use App\Models\User;

beforeEach(function () {
    $this->seed(\Database\Seeders\RbacSeeder::class);

    $this->hr = User::factory()->create([
        'role' => 'hr',
        'approved_at' => now(),
    ]);
    $this->hr->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'hr')->first()->id,
    ]);
});

test('the standalone positions route redirects into the People hub tab', function () {
    $this->actingAs($this->hr)
        ->get('/hr/positions')
        ->assertRedirect(route('hr.people.index', ['tab' => 'positions']));
});

test('the positions route forwards filters to the hub', function () {
    $this->actingAs($this->hr)
        ->get('/hr/positions?q=care&status=active')
        ->assertRedirect(route('hr.people.index', [
            'tab' => 'positions',
            'pq' => 'care',
            'pstatus' => 'active',
        ]));
});

test('the people hub exposes positions data with headcount fields', function () {
    HrPosition::query()->create([
        'tenant_id' => 1,
        'title' => 'Care Lead',
        'code' => 'CL-1',
        'employment_type' => 'full_time',
        'headcount_budget' => 4,
    ]);

    $response = $this->actingAs($this->hr)->get('/hr/people?tab=positions');
    $response->assertOk();

    $row = collect($response->inertiaProps('positions.data'))
        ->firstWhere('code', 'CL-1');

    expect($row)->not->toBeNull();
    expect($row['title'])->toBe('Care Lead');
    expect($row)->toHaveKeys(['current_headcount', 'vacancies', 'description']);
    expect($row['vacancies'])->toBe(4);
});

test('a position can be created from the hub modal endpoint', function () {
    $this->actingAs($this->hr)->post('/hr/positions', [
        'title' => 'Night Support',
        'code' => 'NS-1',
        'employment_type' => 'part_time',
        'fte' => '0.50',
        'headcount_budget' => '3',
    ])->assertRedirect();

    $position = HrPosition::query()->where('code', 'NS-1')->first();
    expect($position)->not->toBeNull();
    expect($position->title)->toBe('Night Support');
    expect($position->tenant_id)->toBe(1);
});

test('a position can be updated from the hub modal endpoint', function () {
    $position = HrPosition::query()->create([
        'tenant_id' => 1,
        'title' => 'Old Title',
        'code' => 'UPD-1',
        'employment_type' => 'full_time',
    ]);

    $this->actingAs($this->hr)->put("/hr/positions/{$position->id}", [
        'title' => 'New Title',
        'code' => 'UPD-1',
        'employment_type' => 'full_time',
        'fte' => '1.00',
        'headcount_budget' => '2',
        'is_active' => true,
    ])->assertRedirect();

    expect($position->fresh()->title)->toBe('New Title');
});
