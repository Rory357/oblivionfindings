<?php

use App\Domain\Hr\Models\HrApplication;
use App\Domain\Hr\Models\HrCandidate;
use App\Domain\Hr\Models\HrJobRequisition;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;

beforeEach(function () {
    $this->seed(RbacSeeder::class);

    $this->hr = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $this->hr->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'hr')->first()->id,
    ]);
});

test('the duplicate job-postings list redirects to the live requisition jobs UI', function () {
    $this->actingAs($this->hr)
        ->get('/hr/job-postings')
        ->assertRedirect(route('hr.jobs.index'));
});

test('candidate applications expose the linked requisition title (not the orphaned posting)', function () {
    $requisition = HrJobRequisition::query()->create([
        'tenant_id' => 1,
        'title' => 'Night Support Worker',
        'slug' => 'night-support-worker',
        'position_role' => 'support_worker',
        'employment_type' => 'full_time',
        'openings' => 1,
        'status' => 'published',
        'created_by' => $this->hr->id,
    ]);

    $candidate = HrCandidate::factory()->create([
        'tenant_id' => 1,
        'status' => 'new',
        'created_by' => $this->hr->id,
    ]);

    HrApplication::factory()->create([
        'tenant_id' => 1,
        'candidate_id' => $candidate->id,
        'requisition_id' => $requisition->id,
        'position_title' => 'Night Support Worker',
        'status' => 'active',
    ]);

    $response = $this->actingAs($this->hr)->get(route('hr.candidates.show', $candidate->id));
    $response->assertOk();

    $apps = collect($response->inertiaProps('candidate.applications'));
    expect($apps)->not->toBeEmpty();
    expect($apps->first()['job_posting']['title'])->toBe('Night Support Worker');
});
