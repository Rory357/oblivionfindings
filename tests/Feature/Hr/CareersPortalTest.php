<?php

use App\Domain\Hr\Models\HrApplication;
use App\Domain\Hr\Models\HrCandidate;
use App\Domain\Hr\Models\HrJobRequisition;
use App\Domain\Hr\Models\HrOffer;
use App\Models\Site;

beforeEach(function () {
    $this->site = Site::factory()->create([
        'type' => 'house',
    ]);
});

test('public careers application creates candidate and application', function () {
    $job = HrJobRequisition::query()->create([
        'title' => 'Night Support Worker',
        'slug' => 'night-support-worker',
        'position_role' => 'support_worker',
        'site_id' => $this->site->id,
        'employment_type' => 'full_time',
        'openings' => 2,
        'status' => 'published',
        'description' => 'Provide overnight support.',
        'published_at' => now(),
    ]);

    $this->post("/careers/jobs/{$job->slug}/apply", [
        'first_name' => 'Jules',
        'last_name' => 'Applicant',
        'preferred_name' => 'J',
        'personal_email' => 'jules.applicant@example.test',
        'personal_phone' => '0210000000',
        'cover_letter' => 'I would love this role.',
        'privacy_consent' => '1',
    ])->assertRedirect("/careers/jobs/{$job->slug}/apply");

    $candidate = HrCandidate::query()->where('personal_email', 'jules.applicant@example.test')->first();

    expect($candidate)->not->toBeNull();
    expect($candidate?->source)->toBe('career_page');

    expect(HrApplication::query()
        ->where('candidate_id', $candidate?->id)
        ->where('requisition_id', $job->id)
        ->exists())->toBeTrue();
});

test('public offer acceptance requires signature and records e-sign details', function () {
    $candidate = HrCandidate::query()->create([
        'first_name' => 'Ari',
        'last_name' => 'Offer',
        'personal_email' => 'ari.offer@example.test',
        'source' => 'direct',
        'status' => 'offer_sent',
        'current_stage_entered_at' => now(),
    ]);

    $application = HrApplication::query()->create([
        'candidate_id' => $candidate->id,
        'position_title' => 'Team Lead',
        'position_role' => 'team_lead',
        'target_site_id' => $this->site->id,
        'status' => 'active',
    ]);

    $offer = HrOffer::query()->create([
        'application_id' => $application->id,
        'position_title' => 'Team Lead',
        'position_role' => 'team_lead',
        'proposed_start_date' => now()->addDays(10)->toDateString(),
        'employment_type' => 'full_time',
        'hours_per_week' => 40,
        'annual_salary' => 78000,
        'primary_site_id' => $this->site->id,
        'approval_status' => 'approved',
        'sent_at' => now(),
        'candidate_portal_token' => 'token-abc-123',
        'portal_expires_at' => now()->addDays(7),
    ]);

    $this->get('/careers/offers/token-abc-123')->assertOk();

    $this->post('/careers/offers/token-abc-123', [
        'response' => 'accepted',
        'terms_accepted' => '1',
    ])->assertSessionHasErrors(['signature_name']);

    $this->post('/careers/offers/token-abc-123', [
        'response' => 'accepted',
        'signature_name' => 'Ari Offer',
        'terms_accepted' => '1',
        'response_notes' => 'Excited to join.',
    ])->assertSessionHas('success');

    $offer->refresh();
    $candidate->refresh();

    expect($offer->response)->toBe('accepted');
    expect($offer->signed_full_name)->toBe('Ari Offer');
    expect($offer->signed_at)->not->toBeNull();
    expect($candidate->status)->toBe('offer_accepted');
});

test('careers index supports role, employment type, and site filters', function () {
    $otherSite = Site::factory()->create([
        'type' => 'house',
    ]);

    HrJobRequisition::query()->create([
        'title' => 'Team Lead',
        'slug' => 'team-lead',
        'position_role' => 'team_lead',
        'site_id' => $otherSite->id,
        'employment_type' => 'full_time',
        'openings' => 1,
        'status' => 'published',
        'published_at' => now(),
    ]);

    $targetJob = HrJobRequisition::query()->create([
        'title' => 'Casual Support Worker',
        'slug' => 'casual-support-worker',
        'position_role' => 'support_worker',
        'site_id' => $this->site->id,
        'employment_type' => 'casual',
        'openings' => 1,
        'status' => 'published',
        'published_at' => now(),
    ]);

    $response = $this->get("/careers?position_role=support_worker&employment_type=casual&site={$this->site->id}");
    $response->assertOk();

    $jobs = collect($response->inertiaProps('jobs'));

    expect($response->inertiaProps('filters.position_role'))->toBe('support_worker');
    expect($response->inertiaProps('filters.employment_type'))->toBe('casual');
    expect($response->inertiaProps('filters.site'))->toBe($this->site->id);
    expect($jobs->pluck('id')->all())->toBe([$targetJob->id]);
});

test('careers application captures sourcing channel metadata', function () {
    $job = HrJobRequisition::query()->create([
        'title' => 'Community Support Worker',
        'slug' => 'community-support-worker',
        'position_role' => 'support_worker',
        'site_id' => $this->site->id,
        'employment_type' => 'full_time',
        'openings' => 1,
        'status' => 'published',
        'published_at' => now(),
    ]);

    $this->post("/careers/jobs/{$job->slug}/apply", [
        'first_name' => 'Ria',
        'last_name' => 'Finder',
        'personal_email' => 'ria.finder@example.test',
        'privacy_consent' => '1',
        'source_channel' => 'linkedin',
        'source_reference' => 'LI-CAMPAIGN-2026',
    ])->assertSessionHas('success');

    $candidate = HrCandidate::query()->where('personal_email', 'ria.finder@example.test')->first();

    expect($candidate)->not->toBeNull();
    expect($candidate?->source)->toBe('linkedin');
    expect($candidate?->source_detail)->toContain('job:community-support-worker');
    expect($candidate?->source_detail)->toContain('ref:LI-CAMPAIGN-2026');
});
