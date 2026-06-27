<?php

use App\Domain\Hr\Jobs\ArchiveCandidateDataJob;
use App\Domain\Hr\Models\HrApplication;
use App\Domain\Hr\Models\HrCandidate;
use App\Domain\Hr\Models\HrCandidateDocument;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrInterview;
use App\Domain\Hr\Models\HrJobRequisition;
use App\Domain\Hr\Models\HrOffer;
use App\Domain\Hr\Models\HrPosition;
use App\Domain\Hr\Models\HrReferenceCheck;
use App\Domain\Hr\Models\HrTalentPool;
use App\Domain\Hr\Notifications\ApplicationConfirmationNotification;
use App\Domain\Hr\Notifications\CandidateHiredNotification;
use App\Domain\Hr\Notifications\InterviewInviteNotification;
use App\Domain\Hr\Notifications\JobApplicationReceivedNotification;
use App\Domain\Hr\Notifications\OfferResponseAckNotification;
use App\Domain\Hr\Notifications\OfferSentNotification;
use App\Domain\Hr\Notifications\ReferenceRequestNotification;
use App\Domain\Hr\Notifications\RejectionNotification;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

function makeOffer(array $ctx, string $state, int $hrId, int $siteId): HrOffer
{
    $base = [
        'application_id' => $ctx['application']->id,
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'proposed_start_date' => now()->addWeeks(2)->toDateString(),
        'employment_type' => 'full_time',
        'hours_per_week' => 40,
        'hourly_rate' => 28.50,
        'primary_site_id' => $siteId,
        'approval_status' => 'approved',
        'created_by' => $hrId,
    ];
    if ($state === 'sent' || $state === 'accepted') {
        $base['sent_at'] = now();
        $base['candidate_portal_token'] = 'tok-'.uniqid();
        $base['portal_expires_at'] = now()->addDays(14);
    }
    if ($state === 'accepted') {
        $base['response'] = 'accepted';
        $base['response_at'] = now();
    }

    return HrOffer::create($base);
}

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

/* ---- A2: offer email + resend (#14) ---- */

test('sending an offer emails the candidate the portal link', function () {
    Notification::fake();
    ['application' => $application, 'candidate' => $candidate] = makeApplicant($this->hr->id, 'interview_completed');
    $offer = makeOffer(['application' => $application], 'draft_approved', $this->hr->id, $this->site->id);

    $this->actingAs($this->hr)->post(route('hr.offers.send', $offer->id))->assertRedirect();

    Notification::assertSentOnDemand(
        OfferSentNotification::class,
        fn ($notification, $channels, $notifiable) => ($notifiable->routes['mail'] ?? null) === $candidate->personal_email,
    );
    expect($offer->fresh()->sent_at)->not->toBeNull();
});

test('resend re-delivers the offer link without re-advancing the stage', function () {
    Notification::fake();
    ['application' => $application, 'candidate' => $candidate] = makeApplicant($this->hr->id, 'offer_sent');
    $offer = makeOffer(['application' => $application], 'sent', $this->hr->id, $this->site->id);

    $this->actingAs($this->hr)->post(route('hr.offers.resend', $offer->id))->assertRedirect();

    Notification::assertSentOnDemand(OfferSentNotification::class);
    // unsent offer cannot be resent
    $unsent = makeOffer(['application' => makeApplicant($this->hr->id)['application']], 'draft_approved', $this->hr->id, $this->site->id);
    $this->actingAs($this->hr)->post(route('hr.offers.resend', $unsent->id))->assertRedirect();
    expect($unsent->fresh()->sent_at)->toBeNull();
});

/* ---- A1: segregation of duties on convert (#9) ---- */

test('converting to an employee requires hr.employees.manage', function () {
    ['application' => $application] = makeApplicant($this->hr->id, 'offer_accepted');
    $offer = makeOffer(['application' => $application], 'accepted', $this->hr->id, $this->site->id);

    // Deny employees.manage for this recruiter while keeping recruitment.manage.
    $deny = Permission::where('key', 'hr.employees.manage')->first();
    $this->hr->permissionOverrides()->attach($deny->id, ['allowed' => false]);

    $this->actingAs($this->hr)->post(route('hr.offers.convert', $offer->id))->assertForbidden();
    expect(HrEmployeeProfile::query()->count())->toBe(0);
});

test('respondOffer does not auto-mint a login without hr.employees.manage', function () {
    ['application' => $application, 'candidate' => $candidate] = makeApplicant($this->hr->id, 'offer_sent');
    $offer = makeOffer(['application' => $application], 'sent', $this->hr->id, $this->site->id);

    $deny = Permission::where('key', 'hr.employees.manage')->first();
    $this->hr->permissionOverrides()->attach($deny->id, ['allowed' => false]);

    $this->actingAs($this->hr)
        ->post(route('hr.offers.respond', $offer->id), ['response' => 'accepted'])
        ->assertRedirect();

    expect($offer->fresh()->response)->toBe('accepted');
    expect($candidate->fresh()->status)->toBe('offer_accepted');
    expect(HrEmployeeProfile::query()->count())->toBe(0);
});

/* ---- A4: offer-letter download is tenant-scoped ---- */

test('offer letter download is tenant-scoped', function () {
    $foreign = HrCandidate::factory()->create(['tenant_id' => 2, 'status' => 'offer_sent', 'created_by' => $this->hr->id]);
    $foreignApp = HrApplication::factory()->create(['tenant_id' => 2, 'candidate_id' => $foreign->id, 'position_title' => 'Nurse', 'status' => 'active']);
    $foreignOffer = makeOffer(['application' => $foreignApp], 'sent', $this->hr->id, $this->site->id);
    $foreignOffer->update(['offer_letter_path' => 'offers/x/letter.pdf', 'offer_letter_name' => 'letter.pdf']);

    // hr user resolves to tenant 1 → cross-tenant letter is not reachable.
    $this->actingAs($this->hr)->get(route('hr.offers.letter', $foreignOffer->id))->assertNotFound();
});

/* ---- A7: offer-response acks (#19) + hire notify ---- */

test('responding to an offer acknowledges the candidate', function () {
    Notification::fake();
    ['application' => $application] = makeApplicant($this->hr->id, 'offer_sent');
    $offer = makeOffer(['application' => $application], 'sent', $this->hr->id, $this->site->id);

    $this->actingAs($this->hr)
        ->post(route('hr.offers.respond', $offer->id), ['response' => 'declined'])
        ->assertRedirect();

    Notification::assertSentOnDemand(OfferResponseAckNotification::class);
});

test('converting notifies the hiring manager and provisions the work email', function () {
    Notification::fake();
    $manager = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $requisition = HrJobRequisition::query()->create([
        'tenant_id' => 1, 'title' => 'Support Worker', 'slug' => 'sw-'.uniqid(),
        'position_role' => 'support_worker', 'employment_type' => 'full_time', 'openings' => 1,
        'status' => 'published', 'hiring_manager_user_id' => $manager->id, 'created_by' => $this->hr->id,
    ]);
    $candidate = HrCandidate::factory()->create(['tenant_id' => 1, 'status' => 'offer_accepted', 'created_by' => $this->hr->id]);
    $application = HrApplication::factory()->create([
        'tenant_id' => 1, 'candidate_id' => $candidate->id, 'requisition_id' => $requisition->id,
        'position_title' => 'Support Worker', 'status' => 'active',
    ]);
    $offer = makeOffer(['application' => $application], 'accepted', $this->hr->id, $this->site->id);

    $this->actingAs($this->hr)->post(route('hr.offers.convert', $offer->id))->assertRedirect();

    Notification::assertSentTo($manager, CandidateHiredNotification::class);
    expect($offer->fresh()->work_email_provisioned)->toBeTrue();
    expect($offer->fresh()->work_email)->not->toBeNull();
    expect(HrEmployeeProfile::query()->count())->toBeGreaterThan(0);
});

/* ---- A8: gated rejection decline email (#18) ---- */

test('rejection emails the candidate only when opted in', function () {
    Notification::fake();
    ['application' => $a1] = makeApplicant($this->hr->id);
    $this->actingAs($this->hr)->post(route('hr.applications.reject', $a1->id), ['rejection_reason' => 'Not a fit'])->assertRedirect();
    Notification::assertNothingSent();

    ['application' => $a2] = makeApplicant($this->hr->id);
    $this->actingAs($this->hr)->post(route('hr.applications.reject', $a2->id), ['send_decline_email' => true])->assertRedirect();
    Notification::assertSentOnDemand(RejectionNotification::class);
});

/* ---- A11: document carries application context ---- */

test('uploading a document carries the application context', function () {
    Storage::fake('private');
    ['application' => $application, 'candidate' => $candidate] = makeApplicant($this->hr->id);

    $this->actingAs($this->hr)->post(route('hr.candidate.documents.store', $candidate->id), [
        'file' => UploadedFile::fake()->create('cv.pdf', 100, 'application/pdf'),
        'category' => 'cv',
    ])->assertRedirect();

    $doc = HrCandidateDocument::query()->where('candidate_id', $candidate->id)->first();
    expect($doc)->not->toBeNull();
    expect($doc->application_id)->toBe($application->id);
});

/* ---- Cross-cutting: retention scrub nulls screening_answers ---- */

test('retention scrub nulls screening_answers and soft-deletes the candidate', function () {
    config(['hr.candidate_retention_months' => 1]);
    $candidate = HrCandidate::factory()->create(['tenant_id' => 1, 'status' => 'rejected', 'created_by' => $this->hr->id]);
    HrCandidate::query()->where('id', $candidate->id)->update(['updated_at' => now()->subMonths(6)]);
    $application = HrApplication::factory()->create([
        'tenant_id' => 1, 'candidate_id' => $candidate->id, 'status' => 'rejected',
        'screening_answers' => ['why' => 'sensitive personal answer'],
    ]);

    (new ArchiveCandidateDataJob(1))->handle();

    expect($application->fresh()->screening_answers)->toBeNull();
    expect(HrCandidate::withTrashed()->find($candidate->id)?->trashed())->toBeTrue();
});

/* ---- A9: analytics keyed on requisition_id (#23) ---- */

test('analytics keys open positions on requisition, not free-text title', function () {
    // Two distinct requisitions that share the same position_title string.
    makeApplicant($this->hr->id, 'screening');
    makeApplicant($this->hr->id, 'new');

    $response = $this->actingAs($this->hr)->get(route('hr.recruitment.index'));
    $response->assertOk();
    $open = collect($response->inertiaProps('analytics.open_positions'));

    // Old (group-by-title) collapsed both into one row; new keys on requisition_id.
    expect($open->whereNotNull('requisition_id')->pluck('requisition_id')->unique()->count())->toBeGreaterThanOrEqual(2);
});

/* ---- A10: server-side streamed export (#26) ---- */

test('server export streams a tenant-scoped pipeline csv', function () {
    ['candidate' => $candidate] = makeApplicant($this->hr->id, 'screening');

    $response = $this->actingAs($this->hr)->get(route('hr.recruitment.export', ['dataset' => 'pipeline', 'format' => 'csv']));
    $response->assertOk();
    $body = $response->streamedContent();
    expect($body)->toContain('Name,Email,Stage');
    expect($body)->toContain($candidate->full_name);

    // invalid dataset → validation redirect
    $this->actingAs($this->hr)->get(route('hr.recruitment.export', ['dataset' => 'bogus']))->assertRedirect();

    // gated on hr.recruitment.view
    $viewer = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $this->actingAs($viewer)->get(route('hr.recruitment.export', ['dataset' => 'pipeline']))->assertForbidden();
});

/* ---- A5: live-path apply notifications (#15) ---- */

test('a public requisition application notifies the candidate and hiring manager', function () {
    Notification::fake();
    $manager = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $job = HrJobRequisition::query()->create([
        'tenant_id' => 1, 'title' => 'Support Worker', 'slug' => 'sw-apply-'.uniqid(),
        'position_role' => 'support_worker', 'employment_type' => 'full_time', 'openings' => 1,
        'status' => 'published', 'hiring_manager_user_id' => $manager->id, 'created_by' => $this->hr->id,
    ]);

    $this->post(route('careers.apply.store', ['job' => $job->slug]), [
        'first_name' => 'Aroha', 'last_name' => 'Ngata',
        'personal_email' => 'aroha.ngata@example.test', 'privacy_consent' => '1',
    ])->assertRedirect();

    Notification::assertSentOnDemand(ApplicationConfirmationNotification::class);
    Notification::assertSentTo($manager, JobApplicationReceivedNotification::class);
});

/* ---- A7 public mirror: portal offer response acks the candidate ---- */

test('a public offer response acknowledges the candidate', function () {
    Notification::fake();
    ['application' => $application] = makeApplicant($this->hr->id, 'offer_sent');
    $offer = makeOffer(['application' => $application], 'sent', $this->hr->id, $this->site->id);

    $resp = $this->post(route('careers.offer.respond', ['token' => $offer->candidate_portal_token]), ['response' => 'declined']);
    $resp->assertRedirect();
    $resp->assertSessionHasNoErrors();
    expect($offer->fresh()->response)->toBe('declined');

    Notification::assertSentOnDemand(OfferResponseAckNotification::class);
});

/* ---- A6: interview invites + .ics + reminder (#16) ---- */

test('scheduling an interview emails the candidate and panel a calendar invite', function () {
    Notification::fake();
    $panelist = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    ['application' => $application] = makeApplicant($this->hr->id, 'screening');

    $this->actingAs($this->hr)->post(route('hr.interviews.store', $application->id), [
        'scheduled_at' => now()->addDays(2)->format('Y-m-d H:i:s'),
        'duration_minutes' => 45,
        'interview_type' => 'in_person',
        'interviewers' => [$panelist->id],
    ])->assertRedirect();

    Notification::assertSentOnDemand(InterviewInviteNotification::class);
    Notification::assertSentTo($panelist, InterviewInviteNotification::class);

    $interview = HrInterview::query()->where('application_id', $application->id)->first();
    expect($interview->invite_sent_at)->not->toBeNull();
});

/* ---- D3: reference questionnaire (#17) ---- */

test('requesting a reference emails the referee a questionnaire link', function () {
    Notification::fake();
    ['application' => $application] = makeApplicant($this->hr->id, 'interview_completed');

    $this->actingAs($this->hr)->post(route('hr.references.store', $application->id), [
        'referee_name' => 'Pat Manager',
        'referee_email' => 'pat.manager@example.test',
        'referee_relationship' => 'Former manager',
    ])->assertRedirect();

    Notification::assertSentOnDemand(ReferenceRequestNotification::class);
    $reference = HrReferenceCheck::query()->where('application_id', $application->id)->first();
    expect($reference->response_token)->not->toBeNull();
    expect($reference->status)->toBe('requested');
});

test('a referee submits the public reference questionnaire', function () {
    ['application' => $application] = makeApplicant($this->hr->id, 'interview_completed');
    $reference = HrReferenceCheck::query()->create([
        'application_id' => $application->id,
        'referee_name' => 'Pat',
        'referee_email' => 'pat@example.test',
        'referee_relationship' => 'Manager',
        'status' => 'requested',
        'requested_at' => now(),
        'response_token' => 'tok-ref-'.uniqid(),
    ]);

    $this->post(route('careers.reference.submit', ['token' => $reference->response_token]), [
        'responses' => ['capacity' => 'Direct manager 2 years', 'reemploy' => 'Yes', 'reliability' => '5'],
    ])->assertRedirect();

    $reference->refresh();
    expect($reference->status)->toBe('completed');
    expect($reference->responses['reemploy'] ?? null)->toBe('Yes');
    expect($reference->received_at)->not->toBeNull();

    // an unknown token 404s
    $this->post(route('careers.reference.submit', ['token' => 'nope']), ['responses' => ['capacity' => 'x']])->assertNotFound();
});

/* ---- D5: talent pool (#22) ---- */

test('a pooled candidate survives the retention archive job', function () {
    config(['hr.candidate_retention_months' => 1]);
    $candidate = HrCandidate::factory()->create(['tenant_id' => 1, 'status' => 'rejected', 'first_name' => 'Keepme', 'created_by' => $this->hr->id]);
    HrCandidate::query()->where('id', $candidate->id)->update(['updated_at' => now()->subMonths(6)]);
    HrTalentPool::query()->create(['tenant_id' => 1, 'candidate_id' => $candidate->id, 'reason' => 'Strong', 'pooled_by' => $this->hr->id]);

    (new ArchiveCandidateDataJob(1))->handle();

    // Pre-fix this candidate would be anonymised + soft-deleted; the guard spares it.
    expect(HrCandidate::withTrashed()->find($candidate->id)?->trashed())->toBeFalse();
    expect($candidate->fresh()->first_name)->toBe('Keepme');
});

test('rejecting with add_to_pool keeps the candidate warm and lists them in the pool', function () {
    ['application' => $application, 'candidate' => $candidate] = makeApplicant($this->hr->id, 'screening');

    $this->actingAs($this->hr)->post(route('hr.applications.reject', $application->id), [
        'rejection_reason' => 'Strong, wrong timing', 'add_to_pool' => true,
    ])->assertRedirect();

    expect(HrTalentPool::query()->where('candidate_id', $candidate->id)->exists())->toBeTrue();

    $response = $this->actingAs($this->hr)->get(route('hr.recruitment.index'));
    $names = collect($response->inertiaProps('pool'))->pluck('name');
    expect($names)->toContain($candidate->full_name);
});

test('reactivating a pooled candidate creates a fresh application and clears the pool entry', function () {
    ['candidate' => $candidate] = makeApplicant($this->hr->id, 'screening');
    HrTalentPool::query()->create(['tenant_id' => 1, 'candidate_id' => $candidate->id, 'reason' => 'x', 'pooled_by' => $this->hr->id]);
    $requisition = HrJobRequisition::query()->create([
        'tenant_id' => 1, 'title' => 'Registered Nurse', 'slug' => 'rn-'.uniqid(),
        'position_role' => 'nurse', 'employment_type' => 'full_time', 'openings' => 1,
        'status' => 'published', 'created_by' => $this->hr->id,
    ]);

    $this->actingAs($this->hr)->post(route('hr.pool.reactivate', $candidate->id), ['requisition_id' => $requisition->id])->assertRedirect();

    expect(HrApplication::query()->where('candidate_id', $candidate->id)->where('requisition_id', $requisition->id)->exists())->toBeTrue();
    expect(HrTalentPool::query()->where('candidate_id', $candidate->id)->exists())->toBeFalse();
    expect($candidate->fresh()->status)->toBe('new');
});

/* ---- D4: bulk actions (#21) ---- */

test('bulk advance moves every selected candidate and skips terminal ones', function () {
    $a = makeApplicant($this->hr->id, 'screening')['candidate'];
    $b = makeApplicant($this->hr->id, 'screening')['candidate'];
    $terminal = makeApplicant($this->hr->id, 'hired')['candidate'];

    $this->actingAs($this->hr)->post(route('hr.applications.bulk'), [
        'action' => 'advance',
        'candidate_ids' => [$a->id, $b->id, $terminal->id],
    ])->assertRedirect();

    expect($a->fresh()->status)->not->toBe('screening');
    expect($b->fresh()->status)->not->toBe('screening');
    expect($terminal->fresh()->status)->toBe('hired'); // skipped, not crashed

    // gated on hr.recruitment.manage
    $viewer = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $this->actingAs($viewer)->post(route('hr.applications.bulk'), ['action' => 'advance', 'candidate_ids' => [$a->id]])->assertForbidden();
});

test('bulk reject closes out every selected candidate', function () {
    $a = makeApplicant($this->hr->id, 'screening');
    $b = makeApplicant($this->hr->id, 'interview_scheduled');

    $this->actingAs($this->hr)->post(route('hr.applications.bulk'), [
        'action' => 'reject',
        'candidate_ids' => [$a['candidate']->id, $b['candidate']->id],
        'reason' => 'Role filled',
    ])->assertRedirect();

    expect($a['candidate']->fresh()->status)->toBe('rejected');
    expect($b['candidate']->fresh()->status)->toBe('rejected');
    expect($a['application']->fresh()->status)->toBe('rejected');
});

test('the interview reminder command sends once for tomorrow', function () {
    $tz = config('app.worker_timezone', 'Pacific/Auckland');
    ['application' => $application] = makeApplicant($this->hr->id, 'interview_scheduled');
    $interview = HrInterview::query()->create([
        'application_id' => $application->id,
        'scheduled_at' => now($tz)->addDay()->setTime(10, 0)->utc(),
        'duration_minutes' => 45,
        'interview_type' => 'in_person',
        'status' => 'scheduled',
    ]);

    Notification::fake();
    $this->artisan('recruitment:send-interview-reminders')->assertSuccessful();
    Notification::assertSentOnDemand(InterviewInviteNotification::class);
    expect($interview->fresh()->reminder_sent_at)->not->toBeNull();

    // Re-running must not double-send.
    Notification::fake();
    $this->artisan('recruitment:send-interview-reminders')->assertSuccessful();
    Notification::assertNothingSent();
});
