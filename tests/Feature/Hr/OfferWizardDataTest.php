<?php

use App\Domain\Hr\Models\HrCandidate;
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
    Site::factory()->create(['tenant_id' => 1, 'type' => 'house', 'name' => 'Kauri House']);

    $candidate = HrCandidate::factory()->create([
        'tenant_id' => 1,
        'first_name' => 'Mia',
        'last_name' => 'Candidate',
        'personal_email' => 'mia.offerdata@example.test',
        'source' => 'direct',
        'status' => 'new',
        'created_by' => $this->hr->id,
    ]);

    $response = $this->actingAs($this->hr)
        ->get(route('hr.candidates.show', $candidate->id));

    $response->assertOk();
    expect($response->inertiaProps('offerSites'))->toBeArray();
    expect($response->inertiaProps('offerRoles'))->toBeArray();
    expect(collect($response->inertiaProps('offerRoles'))->pluck('value'))
        ->toContain('support_worker');
});
