<?php

use App\Domain\Hr\Models\HrApplication;
use App\Domain\Hr\Models\HrCandidate;
use App\Domain\Hr\Models\HrJobRequisition;
use App\Domain\Hr\Models\HrOffer;
use App\Domain\Hr\Models\HrPosition;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;

/**
 * End-to-end coverage for the unified Recruitment hub (`hr/recruitment/index`):
 * the aggregated page contract plus the manager actions the wizards / board /
 * context menus post to. Guards the seat-linkage (`position_id`) writes and the
 * pipeline mutations the prototype's affordances drive.
 */
beforeEach(function () {
    $this->seed(RbacSeeder::class);

    $this->hr = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $this->hr->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'hr')->first()->id,
    ]);

    $this->site = Site::factory()->create(['tenant_id' => 1]);
});

function makeApplicant(int $hrId, string $stage = 'screening'): array
{
    $requisition = HrJobRequisition::query()->create([
        'tenant_id' => 1,
        'title' => 'Support Worker',
        'slug' => 'support-worker-'.uniqid(),
        'position_role' => 'support_worker',
        'employment_type' => 'full_time',
        'openings' => 2,
        'status' => 'published',
        'created_by' => $hrId,
    ]);

    $candidate = HrCandidate::factory()->create([
        'tenant_id' => 1,
        'status' => $stage,
        'current_stage_entered_at' => now()->subDays(3),
        'created_by' => $hrId,
    ]);

    $application = HrApplication::factory()->create([
        'tenant_id' => 1,
        'candidate_id' => $candidate->id,
        'requisition_id' => $requisition->id,
        'position_title' => 'Support Worker',
        'status' => 'active',
    ]);

    return compact('requisition', 'candidate', 'application');
}

test('the unified hub renders with the full aggregated contract', function () {
    ['candidate' => $candidate] = makeApplicant($this->hr->id);

    $response = $this->actingAs($this->hr)->get(route('hr.recruitment.index'));
    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('hr/recruitment/index')
        ->has('hero.funnel')
        ->has('hero.open_requisitions')
        ->has('needs')
        ->has('candidates')
        ->has('requisitions')
        ->has('interviews.week')
        ->has('offers.summary')
        ->has('offers.list')
        ->has('analytics.kpis')
        ->has('analytics.funnel')
        ->has('kits')
        ->has('pool')
        ->has('support.sites')
        ->has('support.positions')
        ->has('support.document_categories')
        ->where('can.manage', true)
    );

    $names = collect($response->inertiaProps('candidates'))->pluck('full_name');
    expect($names)->toContain($candidate->full_name);
});

test('the retired tab routes redirect into the unified hub', function () {
    $this->actingAs($this->hr)->get('/hr/recruitment/kanban')
        ->assertRedirect(route('hr.recruitment.index', ['tab' => 'board']));
    $this->actingAs($this->hr)->get('/hr/recruitment/analytics')
        ->assertRedirect(route('hr.recruitment.index', ['tab' => 'analytics']));
    $this->actingAs($this->hr)->get('/hr/recruitment/jobs')
        ->assertRedirect(route('hr.recruitment.index', ['tab' => 'requisitions']));
    $this->actingAs($this->hr)->get('/hr/recruitment/kits')
        ->assertRedirect(route('hr.recruitment.index', ['tab' => 'kits']));
});

test('advancing an application moves the candidate forward a stage', function () {
    ['candidate' => $candidate, 'application' => $application] = makeApplicant($this->hr->id, 'screening');

    $this->actingAs($this->hr)
        ->post(route('hr.applications.advance', $application->id), ['target_stage' => 'interview_scheduled'])
        ->assertRedirect();

    expect($candidate->fresh()->status)->toBe('interview_scheduled');
});

test('rejecting an application records the reason and closes it out', function () {
    ['application' => $application] = makeApplicant($this->hr->id);

    $this->actingAs($this->hr)
        ->post(route('hr.applications.reject', $application->id), ['rejection_reason' => 'Values mismatch — not a fit'])
        ->assertRedirect();

    $application->refresh();
    expect($application->status)->toBe('rejected');
    expect($application->rejection_reason)->toBe('Values mismatch — not a fit');
});

test('creating a requisition writes the establishment seat (position_id)', function () {
    $position = HrPosition::factory()->create([
        'tenant_id' => 1,
        'title' => 'Support Worker',
        'is_active' => true,
        'headcount_budget' => 5,
        'current_headcount' => 2,
    ]);

    $this->actingAs($this->hr)->post(route('hr.jobs.store'), [
        'title' => 'Support Worker — Hamilton',
        'position_id' => $position->id,
        'employment_type' => 'full_time',
        'openings' => 2,
        'description' => 'Provide person-centred support to clients in their homes.',
        'posting_channels' => ['career_page'],
    ])->assertRedirect();

    $req = HrJobRequisition::query()->where('title', 'Support Worker — Hamilton')->first();
    expect($req)->not->toBeNull();
    expect($req->position_id)->toBe($position->id);
});

test('creating an offer writes position_id and keeps the manager on the hub', function () {
    ['candidate' => $candidate, 'application' => $application] = makeApplicant($this->hr->id, 'interview_completed');
    $position = HrPosition::factory()->create([
        'tenant_id' => 1,
        'title' => 'Support Worker',
        'is_active' => true,
        'headcount_budget' => 5,
        'current_headcount' => 1,
    ]);

    $response = $this->actingAs($this->hr)->post(route('hr.offers.store'), [
        'application_id' => $application->id,
        'position_title' => 'Support Worker',
        'position_id' => $position->id,
        'proposed_start_date' => now()->addWeeks(2)->toDateString(),
        'employment_type' => 'full_time',
        'hours_per_week' => 40,
        'hourly_rate' => 28.50,
        'primary_site_id' => $this->site->id,
    ]);

    // Redirects back to the hub (not the retired candidate show page).
    $response->assertRedirect();
    expect($response->headers->get('Location'))->not->toContain('/candidates/');

    $offer = HrOffer::query()->where('application_id', $application->id)->first();
    expect($offer)->not->toBeNull();
    expect($offer->position_id)->toBe($position->id);
    expect((float) $offer->hourly_rate)->toBe(28.5);
});

test('a view-only user cannot drive manager actions', function () {
    ['application' => $application] = makeApplicant($this->hr->id);

    $viewer = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);

    // Support workers have no recruitment.view — the hub itself is gated.
    $this->actingAs($viewer)->get(route('hr.recruitment.index'))->assertForbidden();
    $this->actingAs($viewer)
        ->post(route('hr.applications.advance', $application->id), ['target_stage' => 'interview_scheduled'])
        ->assertForbidden();
});
