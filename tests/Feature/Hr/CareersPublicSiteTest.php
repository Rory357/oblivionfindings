<?php

use App\Domain\Hr\Jobs\ArchiveCandidateDataJob;
use App\Domain\Hr\Models\HrApplication;
use App\Domain\Hr\Models\HrCandidate;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrJobRequisition;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;

/*
 * Careers inside the public website (mockup v2, approved 23 Oct 2026): the job
 * page, the apply wizard's new work-readiness answers, the staff view of them,
 * and the status page in the site shell.
 */

beforeEach(function () {
    $this->site = Site::factory()->create(['type' => 'house', 'name' => 'Kāpiti Lodge']);
});

function careersSiteJob(Site $site, array $overrides = []): HrJobRequisition
{
    return HrJobRequisition::query()->create([
        'title' => 'Support Worker',
        'slug' => 'support-worker-'.uniqid(),
        'position_role' => 'support_worker',
        'site_id' => $site->id,
        'employment_type' => 'part_time',
        'openings' => 1,
        'status' => 'published',
        'summary' => 'Support three adults in their shared home.',
        'description' => "First paragraph.\n\nSecond paragraph.",
        'requirements' => "- A full or restricted New Zealand driver licence\n- The right to work in New Zealand",
        'published_at' => now(),
        ...$overrides,
    ]);
}

function careersApplicant(array $overrides = []): array
{
    return [
        'first_name' => 'Aroha',
        'last_name' => 'Ngata',
        'personal_email' => 'aroha.'.uniqid().'@example.test',
        'privacy_consent' => '1',
        ...$overrides,
    ];
}

test('an application stores the experience, right to work and driver licence answers', function () {
    $job = careersSiteJob($this->site);

    $this->post("/careers/jobs/{$job->slug}/apply", careersApplicant([
        'years_of_experience' => '1_to_3',
        'experience_summary' => 'Two years at a rest home in Paraparaumu.',
        'right_to_work_status' => 'work_visa',
        'driver_licence_class' => 'restricted',
    ]))
        ->assertSessionHasNoErrors()
        ->assertRedirect("/careers/jobs/{$job->slug}/apply")
        ->assertSessionHas('success');

    $application = HrApplication::query()->where('requisition_id', $job->id)->firstOrFail();

    expect($application->years_of_experience)->toBe('1_to_3');
    expect($application->experience_summary)->toBe('Two years at a rest home in Paraparaumu.');
    expect($application->right_to_work_status)->toBe('work_visa');
    expect($application->driver_licence_class)->toBe('restricted');
});

test('the work-readiness answers are optional, so older forms and API callers still apply', function () {
    $job = careersSiteJob($this->site);

    $this->post("/careers/jobs/{$job->slug}/apply", careersApplicant([
        'years_of_experience' => '',
        'right_to_work_status' => '',
    ]))->assertSessionHasNoErrors()->assertSessionHas('success');

    $application = HrApplication::query()->where('requisition_id', $job->id)->firstOrFail();

    expect($application->years_of_experience)->toBeNull();
    expect($application->experience_summary)->toBeNull();
    expect($application->right_to_work_status)->toBeNull();
    expect($application->driver_licence_class)->toBeNull();
});

test('unknown work-readiness values are refused and nothing is stored', function () {
    $job = careersSiteJob($this->site);

    $this->post("/careers/jobs/{$job->slug}/apply", careersApplicant([
        'years_of_experience' => 'decades',
        'right_to_work_status' => 'maybe',
        'driver_licence_class' => 'heavy_trailer',
        'experience_summary' => str_repeat('a', 2001),
    ]))->assertSessionHasErrors([
        'years_of_experience',
        'right_to_work_status',
        'driver_licence_class',
        'experience_summary',
    ]);

    expect(HrApplication::query()->where('requisition_id', $job->id)->exists())->toBeFalse();
});

test('Recruitment staff see the work-readiness answers on the application', function () {
    $this->seed(RbacSeeder::class);
    $hr = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $hr->roles()->syncWithoutDetaching([Role::query()->where('name', 'hr')->firstOrFail()->id]);
    HrEmployeeProfile::factory()->create([
        'user_id' => $hr->id,
        'primary_site_id' => $this->site->id,
        'secondary_site_ids' => [],
        'position_role' => 'hr',
        'is_active' => true,
        'start_date' => today()->subYear(),
        'end_date' => null,
    ]);
    $job = careersSiteJob($this->site);
    $this->post("/careers/jobs/{$job->slug}/apply", careersApplicant([
        'years_of_experience' => '3_plus',
        'experience_summary' => 'Five years in community support.',
        'right_to_work_status' => 'citizen_or_resident',
        'driver_licence_class' => 'full',
    ]))->assertSessionHasNoErrors();
    $candidate = HrApplication::query()->where('requisition_id', $job->id)->firstOrFail()->candidate;

    $this->actingAs($hr)
        ->get(route('hr.candidates.show', $candidate->id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('hr/candidates/show')
            ->where('candidate.applications.0.years_of_experience', '3_plus')
            ->where('candidate.applications.0.experience_summary', 'Five years in community support.')
            ->where('candidate.applications.0.right_to_work_status', 'citizen_or_resident')
            ->where('candidate.applications.0.driver_licence_class', 'full'));
});

test('the retention scrub clears the work-readiness answers with the rest of the application', function () {
    config(['hr.candidate_retention_months' => 1]);
    $candidate = HrCandidate::factory()->create(['status' => 'rejected']);
    HrCandidate::query()->whereKey($candidate->id)->update(['updated_at' => now()->subMonths(6)]);
    $application = HrApplication::factory()->create([
        'candidate_id' => $candidate->id,
        'status' => 'rejected',
        'experience_summary' => 'Personal history',
        'years_of_experience' => 'under_1',
        'right_to_work_status' => 'work_visa',
        'driver_licence_class' => 'none',
    ]);

    (new ArchiveCandidateDataJob)->handle();

    $application->refresh();
    expect($application->experience_summary)->toBeNull();
    expect($application->years_of_experience)->toBeNull();
    expect($application->right_to_work_status)->toBeNull();
    expect($application->driver_licence_class)->toBeNull();
});

test('a role has its own public page, and the apply link opens it with the wizard open', function () {
    $job = careersSiteJob($this->site, [
        'show_salary' => true,
        'salary_range_min' => 28.5,
        'salary_range_max' => 31,
        'screening_questions' => ['Which days can you work?', '  '],
    ]);

    $this->get("/careers/jobs/{$job->slug}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('careers/apply')
            ->where('openApply', false)
            ->where('job.slug', $job->slug)
            ->where('job.site.name', 'Kāpiti Lodge')
            ->where('job.pay', '$28.50 to $31.00 an hour')
            ->where('job.screening_questions', ['Which days can you work?'])
            ->has('provider.name'));

    $this->get("/careers/jobs/{$job->slug}/apply")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('careers/apply')
            ->where('openApply', true));
});

test('pay stays off the public page unless the role shows it on the ad', function () {
    $hidden = careersSiteJob($this->site, ['salary_range_min' => 68000, 'salary_range_max' => 76000]);
    $annual = careersSiteJob($this->site, ['show_salary' => true, 'salary_range_min' => 68000, 'salary_range_max' => 76000]);

    $this->get("/careers/jobs/{$hidden->slug}")
        ->assertInertia(fn ($page) => $page->where('job.pay', null));
    $this->get("/careers/jobs/{$annual->slug}")
        ->assertInertia(fn ($page) => $page->where('job.pay', '$68,000 to $76,000 a year'));
});

test('draft and closed roles have no public page', function () {
    $draft = careersSiteJob($this->site, ['status' => 'draft']);
    $closed = careersSiteJob($this->site, ['closing_at' => today()->subDay()]);

    $this->get("/careers/jobs/{$draft->slug}")->assertNotFound();
    $this->get("/careers/jobs/{$closed->slug}")->assertNotFound();
    $this->get("/careers/jobs/{$closed->slug}/apply")->assertNotFound();
});

test('the careers list and the status page carry the site shell details', function () {
    $job = careersSiteJob($this->site);
    $this->post("/careers/jobs/{$job->slug}/apply", careersApplicant())->assertSessionHasNoErrors();
    $application = HrApplication::query()->where('requisition_id', $job->id)->firstOrFail();

    $this->get('/careers')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('careers/index')
            ->has('provider.name')
            ->where('jobs.0.slug', $job->slug));

    $this->get("/careers/application/{$application->candidate_tracking_token}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('careers/application-status')
            ->has('provider.name')
            ->where('application.posting.location', 'Kāpiti Lodge')
            ->where('application.status_label', 'Application received'));

    $this->get('/careers/application/not-a-real-token')->assertNotFound();
});
