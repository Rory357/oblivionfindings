<?php

use App\Domain\Hr\Jobs\ArchiveCandidateDataJob;
use App\Domain\Hr\Models\HrApplication;
use App\Domain\Hr\Models\HrCandidate;
use App\Domain\Hr\Models\HrCandidateDocument;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrInterview;
use App\Domain\Hr\Models\HrInterviewKit;
use App\Domain\Hr\Models\HrInterviewScore;
use App\Domain\Hr\Models\HrJobRequisition;
use App\Domain\Hr\Models\HrOffer;
use App\Domain\Hr\Models\HrPosition;
use App\Domain\Hr\Models\HrReferenceCheck;
use App\Domain\Hr\Models\HrTalentPool;
use App\Domain\Hr\Notifications\ApplicationConfirmationNotification;
use App\Domain\Hr\Notifications\CandidateHiredNotification;
use App\Domain\Hr\Notifications\CandidateMessageNotification;
use App\Domain\Hr\Notifications\InterviewInviteNotification;
use App\Domain\Hr\Notifications\JobApplicationReceivedNotification;
use App\Domain\Hr\Notifications\NewHireWelcomeNotification;
use App\Domain\Hr\Notifications\OfferApprovalNotification;
use App\Domain\Hr\Notifications\OfferResponseAckNotification;
use App\Domain\Hr\Notifications\OfferSentNotification;
use App\Domain\Hr\Notifications\ReferenceRequestNotification;
use App\Domain\Hr\Notifications\RejectionNotification;
use App\Domain\Hr\Notifications\RequisitionApprovalRequestNotification;
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

test('an offer with no uploaded letter generates a branded PDF on download', function () {
    ['application' => $application] = makeApplicant($this->hr->id, 'offer_sent');
    $offer = makeOffer(['application' => $application], 'sent', $this->hr->id, $this->site->id);
    expect($offer->offer_letter_path)->toBeNull();

    $response = $this->actingAs($this->hr)->get(route('hr.offers.letter', $offer->id));

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('application/pdf');
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
    $candidate = HrCandidate::factory()->create(['tenant_id' => 1, 'status' => 'offer_accepted', 'personal_email' => 'new.hire@example.test', 'created_by' => $this->hr->id]);
    $application = HrApplication::factory()->create([
        'tenant_id' => 1, 'candidate_id' => $candidate->id, 'requisition_id' => $requisition->id,
        'position_title' => 'Support Worker', 'status' => 'active',
    ]);
    $offer = makeOffer(['application' => $application], 'accepted', $this->hr->id, $this->site->id);

    $this->actingAs($this->hr)->post(route('hr.offers.convert', $offer->id))->assertRedirect();

    Notification::assertSentTo($manager, CandidateHiredNotification::class);
    // The new hire gets a branded welcome on their personal inbox.
    Notification::assertSentOnDemand(NewHireWelcomeNotification::class);
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

test('a public application captures screening answers against the requisition questions', function () {
    $job = HrJobRequisition::query()->create([
        'tenant_id' => 1, 'title' => 'Support Worker', 'slug' => 'sw-screen-'.uniqid(),
        'position_role' => 'support_worker', 'employment_type' => 'full_time', 'openings' => 1,
        'status' => 'published',
        'screening_questions' => ['Do you hold a current NZ driver licence?', 'Are you available for weekend shifts?'],
        'created_by' => $this->hr->id,
    ]);

    $this->post(route('careers.apply.store', ['job' => $job->slug]), [
        'first_name' => 'Mere', 'last_name' => 'Rangi',
        'personal_email' => 'mere.rangi@example.test', 'privacy_consent' => '1',
        'screening_answers' => [
            ['question' => 'Do you hold a current NZ driver licence?', 'answer' => 'Yes, full licence'],
            ['question' => 'Are you available for weekend shifts?', 'answer' => 'Saturdays only'],
            // A rogue client-injected question must be ignored — answers are
            // rebuilt server-side from the requisition's configured questions.
            ['question' => 'Injected rogue question', 'answer' => 'should be dropped'],
        ],
    ])->assertRedirect();

    $application = HrApplication::query()->where('requisition_id', $job->id)->first();
    expect($application)->not->toBeNull();
    // Order matches the requisition's configured questions; the rogue injected
    // question is dropped. toEqual (not toBe) — inner key order is immaterial.
    expect($application->screening_answers)->toEqual([
        ['question' => 'Do you hold a current NZ driver licence?', 'answer' => 'Yes, full licence'],
        ['question' => 'Are you available for weekend shifts?', 'answer' => 'Saturdays only'],
    ]);
    expect(collect($application->screening_answers)->pluck('question')->all())
        ->not->toContain('Injected rogue question');
});

test('a public application is trackable via a requisition-aware status page', function () {
    $job = HrJobRequisition::query()->create([
        'tenant_id' => 1, 'title' => 'Support Worker', 'slug' => 'sw-track-'.uniqid(),
        'position_role' => 'support_worker', 'employment_type' => 'full_time', 'openings' => 1,
        'status' => 'published', 'created_by' => $this->hr->id,
    ]);

    $this->post(route('careers.apply.store', ['job' => $job->slug]), [
        'first_name' => 'Hemi', 'last_name' => 'Tane',
        'personal_email' => 'hemi.tane@example.test', 'privacy_consent' => '1',
    ])->assertRedirect();

    $application = HrApplication::query()->where('requisition_id', $job->id)->first();
    // The confirmation email links to /careers/application/{token}, so the token must be set.
    expect($application->candidate_tracking_token)->not->toBeNull();

    // The public status page renders off the requisition (no job posting involved).
    $this->get(route('careers.application.status', ['token' => $application->candidate_tracking_token]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('careers/application-status')
            ->where('application.posting.title', 'Support Worker'));
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

/* ---- Interview-kit editor (Kits tab made editable) ---- */

test('a manager can create, edit and toggle an interview kit', function () {
    $this->actingAs($this->hr)->post(route('hr.kits.store'), [
        'name' => 'Support Worker scorecard',
        'role' => 'support_worker',
        'criteria' => [['label' => 'Values', 'weight' => 40], ['label' => 'Reliability', 'weight' => 60]],
    ])->assertRedirect();

    $kit = HrInterviewKit::query()->where('name', 'Support Worker scorecard')->first();
    expect($kit)->not->toBeNull();
    expect($kit->criteria)->toHaveCount(2);
    expect($kit->is_active)->toBeTrue();

    $this->actingAs($this->hr)->put(route('hr.kits.update', $kit->id), [
        'name' => 'SW scorecard v2',
        'criteria' => [['label' => 'Values', 'weight' => 100]],
    ])->assertRedirect();
    expect($kit->fresh()->name)->toBe('SW scorecard v2');
    expect($kit->fresh()->criteria)->toHaveCount(1);

    $this->actingAs($this->hr)->post(route('hr.kits.toggleActive', $kit->id))->assertRedirect();
    expect($kit->fresh()->is_active)->toBeFalse();

    $viewer = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $this->actingAs($viewer)->post(route('hr.kits.store'), ['name' => 'x'])->assertForbidden();
});

/* ---- D7: requisition salary + approval workflow (#5) ---- */

test('a requisition stores salary, screening and approval fields', function () {
    $this->actingAs($this->hr)->post(route('hr.jobs.store'), [
        'title' => 'Support Worker — Pay',
        'employment_type' => 'full_time',
        'openings' => 1,
        'description' => 'Provide person-centred support.',
        'salary_range_min' => 26,
        'salary_range_max' => 30,
        'show_salary' => true,
        'requires_approval' => true,
        'screening_questions' => ['Do you hold a current NZ driver licence?'],
    ])->assertRedirect();

    $req = HrJobRequisition::query()->where('title', 'Support Worker — Pay')->first();
    expect((float) $req->salary_range_min)->toBe(26.0);
    expect($req->show_salary)->toBeTrue();
    expect($req->requires_approval)->toBeTrue();
    expect($req->screening_questions)->toBe(['Do you hold a current NZ driver licence?']);
});

test('the requisition approval workflow transitions and notifies', function () {
    Notification::fake();
    $manager = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $req = HrJobRequisition::query()->create([
        'tenant_id' => 1, 'title' => 'Team Leader', 'slug' => 'tl-'.uniqid(),
        'position_role' => 'lead', 'employment_type' => 'full_time', 'openings' => 1,
        'status' => 'draft', 'requires_approval' => true, 'hiring_manager_user_id' => $manager->id,
        'created_by' => $this->hr->id,
    ]);

    $this->actingAs($this->hr)->post(route('hr.jobs.submit-approval', $req->id))->assertRedirect();
    expect($req->fresh()->status)->toBe('pending_approval');
    Notification::assertSentTo($manager, RequisitionApprovalRequestNotification::class);

    $this->actingAs($this->hr)->post(route('hr.jobs.approve', $req->id))->assertRedirect();
    expect($req->fresh()->status)->toBe('published');

    $req2 = HrJobRequisition::query()->create([
        'tenant_id' => 1, 'title' => 'BSP', 'slug' => 'bsp-'.uniqid(),
        'position_role' => 'bsp', 'employment_type' => 'full_time', 'openings' => 1,
        'status' => 'pending_approval', 'created_by' => $this->hr->id,
    ]);
    $this->actingAs($this->hr)->post(route('hr.jobs.reject-approval', $req2->id))->assertRedirect();
    expect($req2->fresh()->status)->toBe('draft');
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

test('bulk email sends a message to every selected candidate', function () {
    Notification::fake();
    $a = HrCandidate::factory()->create(['tenant_id' => 1, 'status' => 'screening', 'personal_email' => 'a.cand@example.test', 'created_by' => $this->hr->id]);
    $b = HrCandidate::factory()->create(['tenant_id' => 1, 'status' => 'screening', 'personal_email' => 'b.cand@example.test', 'created_by' => $this->hr->id]);

    $this->actingAs($this->hr)->post(route('hr.candidates.bulk-email'), [
        'candidate_ids' => [$a->id, $b->id],
        'subject' => 'An update on your application',
        'body' => "Thanks for your patience.\nWe will be in touch next week.",
    ])->assertRedirect();

    Notification::assertSentOnDemand(CandidateMessageNotification::class);
    Notification::assertCount(2);

    // subject + body are required
    $this->actingAs($this->hr)->post(route('hr.candidates.bulk-email'), [
        'candidate_ids' => [$a->id], 'subject' => '', 'body' => '',
    ])->assertSessionHasErrors(['subject', 'body']);

    // gated on hr.recruitment.manage
    $viewer = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $this->actingAs($viewer)->post(route('hr.candidates.bulk-email'), [
        'candidate_ids' => [$a->id], 'subject' => 'x', 'body' => 'y',
    ])->assertForbidden();
});

test('bulk pool warm-banks every selected candidate', function () {
    $a = makeApplicant($this->hr->id, 'screening')['candidate'];
    $b = makeApplicant($this->hr->id, 'interview_completed')['candidate'];

    $this->actingAs($this->hr)->post(route('hr.applications.bulk'), [
        'action' => 'pool',
        'candidate_ids' => [$a->id, $b->id],
        'reason' => 'Strong but no seat right now',
    ])->assertRedirect();

    expect(HrTalentPool::query()->where('candidate_id', $a->id)->exists())->toBeTrue();
    expect(HrTalentPool::query()->where('candidate_id', $b->id)->exists())->toBeTrue();
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

/* ---- Interview scoring entry (Interviews tab → Score dialog) ---- */

test('scoring an interview against its kit persists a weighted scorecard and completes the interview', function () {
    ['application' => $application] = makeApplicant($this->hr->id, 'interview_scheduled');

    $kit = HrInterviewKit::query()->create([
        'tenant_id' => 1,
        'name' => 'SW panel',
        'role' => 'support_worker',
        'criteria' => [['label' => 'Values', 'weight' => 40], ['label' => 'Reliability', 'weight' => 60]],
        'is_active' => true,
        'created_by' => $this->hr->id,
    ]);
    $application->update(['interview_kit_id' => $kit->id]);

    $interview = HrInterview::query()->create([
        'application_id' => $application->id,
        'scheduled_at' => now()->subHour()->utc(),
        'duration_minutes' => 45,
        'interview_type' => 'in_person',
        'status' => 'scheduled',
    ]);

    $this->actingAs($this->hr)->post(route('hr.interviews.score', $interview->id), [
        'recommendation' => 'yes',
        'notes' => 'Strong values fit.',
        'criteria_scores' => [
            ['label' => 'Values', 'score' => 80, 'weight' => 40],
            ['label' => 'Reliability', 'score' => 60, 'weight' => 60],
        ],
    ])->assertRedirect();

    $score = HrInterviewScore::query()->where('interview_id', $interview->id)->first();
    expect($score)->not->toBeNull();
    expect($score->interviewer_user_id)->toBe($this->hr->id);
    expect($score->kit_id)->toBe($kit->id);
    expect($score->recommendation)->toBe('yes');
    // Weighted: 80*0.4 + 60*0.6 = 32 + 36 = 68.
    expect(round((float) $score->overall_score))->toBe(68.0);
    expect($score->criteria_scores)->toHaveCount(2);
    expect($interview->fresh()->status)->toBe('completed');
});

test('re-scoring an interview updates the same interviewer scorecard rather than duplicating', function () {
    ['application' => $application] = makeApplicant($this->hr->id, 'interview_scheduled');
    $interview = HrInterview::query()->create([
        'application_id' => $application->id,
        'scheduled_at' => now()->subHour()->utc(),
        'duration_minutes' => 30,
        'interview_type' => 'phone',
        'status' => 'scheduled',
    ]);

    $this->actingAs($this->hr)->post(route('hr.interviews.score', $interview->id), [
        'overall_score' => 55, 'recommendation' => 'maybe',
    ])->assertRedirect();
    $this->actingAs($this->hr)->post(route('hr.interviews.score', $interview->id), [
        'overall_score' => 90, 'recommendation' => 'strong_yes',
    ])->assertRedirect();

    $scores = HrInterviewScore::query()->where('interview_id', $interview->id)->get();
    expect($scores)->toHaveCount(1);
    expect(round((float) $scores->first()->overall_score))->toBe(90.0);
    expect($scores->first()->recommendation)->toBe('strong_yes');
});

test('scoring requires recruitment manage and rejects unknown criteria labels', function () {
    ['application' => $application] = makeApplicant($this->hr->id, 'interview_scheduled');
    $kit = HrInterviewKit::query()->create([
        'tenant_id' => 1, 'name' => 'Kit', 'criteria' => [['label' => 'Values', 'weight' => 100]],
        'is_active' => true, 'created_by' => $this->hr->id,
    ]);
    $application->update(['interview_kit_id' => $kit->id]);
    $interview = HrInterview::query()->create([
        'application_id' => $application->id, 'scheduled_at' => now()->utc(),
        'duration_minutes' => 30, 'interview_type' => 'phone', 'status' => 'scheduled',
    ]);

    // Unknown label is rejected (kit mismatch).
    $this->actingAs($this->hr)->post(route('hr.interviews.score', $interview->id), [
        'criteria_scores' => [['label' => 'Charisma', 'score' => 90, 'weight' => 100]],
    ])->assertSessionHasErrors('criteria_scores');
    expect(HrInterviewScore::query()->count())->toBe(0);

    // A view-only user cannot score.
    $viewer = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $this->actingAs($viewer)->post(route('hr.interviews.score', $interview->id), [
        'overall_score' => 70,
    ])->assertForbidden();
});

/* ---- C1: screening answers persist; legacy `answers` column retired ---- */

test('createApplication persists screening answers and the legacy answers column is gone', function () {
    ['candidate' => $candidate] = makeApplicant($this->hr->id, 'screening');

    $application = app(\App\Domain\Hr\Services\RecruitmentService::class)->createApplication(
        $candidate->fresh(),
        [
            'position_title' => 'Team Leader',
            'screening_answers' => ['drivers_licence' => 'Yes', 'availability' => 'Weekends'],
        ],
    );

    expect($application->screening_answers)->toBe(['drivers_licence' => 'Yes', 'availability' => 'Weekends']);
    // The dead column is dropped by migration.
    expect(\Illuminate\Support\Facades\Schema::hasColumn('hr_applications', 'answers'))->toBeFalse();
});

/* ---- Analytics date-range filter (Analytics tab) ---- */

test('analytics conversion and sources honour the date window', function () {
    HrCandidate::factory()->create(['tenant_id' => 1, 'status' => 'screening', 'source' => 'seek', 'created_at' => now()->subYear(), 'created_by' => $this->hr->id]);
    HrCandidate::factory()->create(['tenant_id' => 1, 'status' => 'screening', 'source' => 'referral', 'created_at' => now()->subDays(2), 'created_by' => $this->hr->id]);

    $svc = app(\App\Domain\Hr\Services\RecruitmentAnalyticsService::class);

    $allScreening = collect($svc->getPipelineConversion(1))->firstWhere('stage', 'screening')['count'];
    expect($allScreening)->toBe(2);

    $from = now()->subWeek()->toDateString();
    $to = now()->addDay()->toDateString();

    $windowedScreening = collect($svc->getPipelineConversion(1, $from, $to))->firstWhere('stage', 'screening')['count'];
    expect($windowedScreening)->toBe(1);

    $sources = collect($svc->getSourceEffectiveness(1, $from, $to))->pluck('source');
    expect($sources->all())->toContain('referral');
    expect($sources->all())->not->toContain('seek');
});

/* ---- Saved candidate email templates (reusable bulk-email) ---- */

test('a manager can save, surface and remove a candidate email template', function () {
    $this->actingAs($this->hr)->post(route('hr.email-templates.store'), [
        'name' => 'Interview invite',
        'subject' => 'Invitation to interview',
        'body' => "We'd love to meet you.\nPlease pick a time that suits.",
    ])->assertRedirect();

    $template = \App\Domain\Hr\Models\HrCandidateEmailTemplate::query()->where('name', 'Interview invite')->first();
    expect($template)->not->toBeNull();
    expect($template->subject)->toBe('Invitation to interview');
    expect($template->tenant_id)->not->toBeNull();

    // The hub surfaces it for the compose dialog.
    $this->actingAs($this->hr)->get(route('hr.recruitment.index'))
        ->assertInertia(fn ($page) => $page
            ->has('email_templates', 1)
            ->where('email_templates.0.name', 'Interview invite')
            ->where('email_templates.0.subject', 'Invitation to interview'));

    // Remove it.
    $this->actingAs($this->hr)->delete(route('hr.email-templates.destroy', $template->id))->assertRedirect();
    expect(\App\Domain\Hr\Models\HrCandidateEmailTemplate::query()->count())->toBe(0);

    // Gated on hr.recruitment.manage.
    $viewer = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $this->actingAs($viewer)->post(route('hr.email-templates.store'), [
        'name' => 'x', 'subject' => 'y', 'body' => 'z',
    ])->assertForbidden();
});

/* ---- Candidate ranking by interview score ---- */

test('the pipeline surfaces a candidate average interview score for ranking', function () {
    ['application' => $application, 'candidate' => $candidate] = makeApplicant($this->hr->id, 'interview_completed');
    $interview = HrInterview::query()->create([
        'application_id' => $application->id,
        'scheduled_at' => now()->subDay()->utc(),
        'duration_minutes' => 30, 'interview_type' => 'phone', 'status' => 'completed',
    ]);
    HrInterviewScore::query()->create([
        'interview_id' => $interview->id, 'interviewer_user_id' => $this->hr->id,
        'overall_score' => 80, 'submitted_at' => now(),
    ]);
    $other = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    HrInterviewScore::query()->create([
        'interview_id' => $interview->id, 'interviewer_user_id' => $other->id,
        'overall_score' => 60, 'submitted_at' => now(),
    ]);

    $this->actingAs($this->hr)->get(route('hr.recruitment.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('candidates', function ($candidates) use ($candidate) {
            $row = collect($candidates)->firstWhere('id', $candidate->id);
            // Average of 80 and 60 across two scorecards.
            expect($row['score'])->toBe(70);
            expect($row['score_count'])->toBe(2);

            return true;
        }));
});

/* ---- Offer approval chain ---- */

test('an offer must be submitted, approved (or declined) before it can be sent', function () {
    Notification::fake();
    $manager = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $manager->roles()->syncWithoutDetaching([Role::query()->where('name', 'hr')->first()->id]);
    $req = HrJobRequisition::query()->create([
        'tenant_id' => 1, 'title' => 'Support Worker', 'slug' => 'sw-appr-'.uniqid(),
        'position_role' => 'support_worker', 'employment_type' => 'full_time', 'openings' => 1,
        'status' => 'published', 'hiring_manager_user_id' => $manager->id, 'created_by' => $this->hr->id,
    ]);
    $candidate = HrCandidate::factory()->create(['tenant_id' => 1, 'status' => 'offer_pending', 'personal_email' => 'c.appr@example.test', 'created_by' => $this->hr->id]);
    $application = HrApplication::factory()->create([
        'tenant_id' => 1, 'candidate_id' => $candidate->id, 'requisition_id' => $req->id,
        'position_title' => 'Support Worker', 'status' => 'active',
    ]);
    $offer = HrOffer::create([
        'application_id' => $application->id, 'position_title' => 'Support Worker', 'position_role' => 'support_worker',
        'proposed_start_date' => now()->addWeeks(2)->toDateString(), 'employment_type' => 'full_time',
        'hours_per_week' => 40, 'hourly_rate' => 28.5, 'primary_site_id' => $this->site->id,
        'approval_status' => 'draft', 'created_by' => $this->hr->id,
    ]);

    // A draft offer cannot be sent.
    $this->actingAs($this->hr)->post(route('hr.offers.send', $offer->id))->assertRedirect();
    expect($offer->fresh()->sent_at)->toBeNull();

    // Submit → pending_approval, notifies the hiring-manager approver.
    $this->actingAs($this->hr)->post(route('hr.offers.submit-approval', $offer->id))->assertRedirect();
    expect($offer->fresh()->approval_status)->toBe('pending_approval');
    expect($offer->fresh()->approval_requested_at)->not->toBeNull();
    Notification::assertSentTo($manager, OfferApprovalNotification::class);

    // Decline → declined with reason, notifies the creator.
    $this->actingAs($manager)->post(route('hr.offers.decline-approval', $offer->id), ['reason' => 'Rate too high'])->assertRedirect();
    expect($offer->fresh()->approval_status)->toBe('declined');
    expect($offer->fresh()->approval_declined_reason)->toBe('Rate too high');
    Notification::assertSentTo($this->hr, OfferApprovalNotification::class);

    // Resubmit then approve → approved (creator notified), now sendable.
    $this->actingAs($this->hr)->post(route('hr.offers.submit-approval', $offer->id))->assertRedirect();
    $this->actingAs($manager)->post(route('hr.offers.approve', $offer->id))->assertRedirect();
    expect($offer->fresh()->approval_status)->toBe('approved');

    $this->actingAs($this->hr)->post(route('hr.offers.send', $offer->id))->assertRedirect();
    expect($offer->fresh()->sent_at)->not->toBeNull();

    // Manage-gated.
    $viewer = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $this->actingAs($viewer)->post(route('hr.offers.submit-approval', $offer->id))->assertForbidden();
});

test('the hub surfaces offer approval states and the approve/send nudges', function () {
    // An offer awaiting sign-off (the approver's queue).
    $ctxA = makeApplicant($this->hr->id, 'offer_pending');
    $pending = makeOffer($ctxA, 'draft', $this->hr->id, $this->site->id);
    $pending->update(['approval_status' => 'pending_approval', 'approval_requested_at' => now()]);

    // An approved-but-unsent offer (ready to send).
    $ctxB = makeApplicant($this->hr->id, 'offer_pending');
    makeOffer($ctxB, 'draft', $this->hr->id, $this->site->id); // makeOffer defaults approval_status='approved'

    // An approver-declined offer surfaces distinctly (not as a fresh "draft").
    $ctxC = makeApplicant($this->hr->id, 'offer_pending');
    $declined = makeOffer($ctxC, 'draft', $this->hr->id, $this->site->id);
    $declined->update(['approval_status' => 'declined', 'approval_declined_reason' => 'Rate too high']);

    $response = $this->actingAs($this->hr)->get(route('hr.recruitment.index'));
    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('offers.list', fn ($list) => collect($list)
            ->contains(fn ($o) => (int) $o['id'] === (int) $pending->id && $o['status'] === 'pending_approval')
            && collect($list)->contains(fn ($o) => (int) $o['id'] === (int) $declined->id && $o['status'] === 'changes_requested'))
        ->where('needs', fn ($needs) => collect($needs)->contains(fn ($n) => $n['key'] === 'offers_approval')
            && collect($needs)->contains(fn ($n) => $n['key'] === 'offers_send'))
    );
});
