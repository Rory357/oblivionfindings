<?php

use App\Domain\Hr\Models\HrApplication;
use App\Domain\Hr\Models\HrCandidate;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;

beforeEach(function () {
    $this->seed(RbacSeeder::class);

    $this->hr = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $this->hr->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'hr')->first()->id,
    ]);
});

test('the candidate page ships the offer wizard option data', function () {
    $site = Site::factory()->create(['type' => 'house', 'name' => 'Kauri House']);
    HrEmployeeProfile::factory()->create([
        'user_id' => $this->hr->id,
        'primary_site_id' => $site->id,
        'is_active' => true,
        'created_by' => $this->hr->id,
        'updated_by' => $this->hr->id,
    ]);

    $candidate = HrCandidate::factory()->create([
        'first_name' => 'Mia',
        'last_name' => 'Candidate',
        'personal_email' => 'mia.offerdata@example.test',
        'source' => 'direct',
        'status' => 'new',
        'created_by' => $this->hr->id,
    ]);
    HrApplication::factory()->create([
        'candidate_id' => $candidate->id,
        'target_site_id' => $site->id,
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
    ]);

    $response = $this->actingAs($this->hr)
        ->get(route('hr.candidates.show', $candidate->id));

    $response->assertOk();
    expect($response->inertiaProps('offerSites'))->toBeArray();
    expect($response->inertiaProps('offerRoles'))->toBeArray();
    expect(collect($response->inertiaProps('offerRoles'))->pluck('value'))
        ->toContain('support_worker');
});
