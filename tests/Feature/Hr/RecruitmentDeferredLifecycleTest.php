<?php

use App\Domain\Hr\Models\HrApplication;
use App\Domain\Hr\Models\HrCandidate;
use App\Domain\Hr\Models\HrInterview;
use App\Domain\Hr\Models\HrInterviewScore;
use App\Domain\Hr\Models\HrOffer;
use App\Domain\Hr\Models\HrReferenceCheck;
use App\Domain\Hr\Services\RecruitmentService;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->hr = User::factory()->create([
        'organization_id' => 1,
        'role' => 'hr',
        'approved_at' => now(),
    ]);
    $this->hr->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'hr')->firstOrFail()->id,
    ]);
    $this->site = Site::factory()->create(['tenant_id' => 1]);
});

function deferredRecruitmentApplication(User $actor, string $stage): array
{
    $candidate = HrCandidate::factory()->create([
        'tenant_id' => 1,
        'status' => $stage,
        'created_by' => $actor->id,
        'updated_by' => $actor->id,
    ]);
    $application = HrApplication::factory()->create([
        'tenant_id' => 1,
        'candidate_id' => $candidate->id,
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'status' => 'active',
    ]);

    return compact('candidate', 'application');
}

function deferredSentOffer(User $actor, Site $site, ?string $response = null): array
{
    ['candidate' => $candidate, 'application' => $application] = deferredRecruitmentApplication(
        $actor,
        $response === 'accepted' ? 'offer_accepted' : 'offer_sent',
    );
    $token = 'deferred-offer-'.str()->random(32);
    $offer = HrOffer::query()->create([
        'application_id' => $application->id,
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'proposed_start_date' => now()->addWeeks(2)->toDateString(),
        'employment_type' => 'full_time',
        'hours_per_week' => 40,
        'hourly_rate' => 30,
        'primary_site_id' => $site->id,
        'approval_status' => 'approved',
        'approved_by' => $actor->id,
        'approved_at' => now(),
        'sent_at' => now()->subDay(),
        'candidate_portal_token' => $token,
        'portal_expires_at' => now()->addWeeks(2),
        'response' => $response,
        'response_at' => $response ? now() : null,
        'created_by' => $actor->id,
    ]);

    return compact('candidate', 'application', 'offer', 'token');
}

function deferredCompletedPanel(User $actor, array $interviewerIds, array $submittedBy): array
{
    ['candidate' => $candidate, 'application' => $application] = deferredRecruitmentApplication($actor, 'interview_completed');
    $interview = HrInterview::query()->create([
        'application_id' => $application->id,
        'scheduled_at' => now()->subDay(),
        'duration_minutes' => 60,
        'interview_type' => 'panel',
        'interviewers' => $interviewerIds,
        'status' => 'completed',
        'completed_by' => $actor->id,
    ]);
    foreach ($submittedBy as $userId) {
        HrInterviewScore::query()->create([
            'interview_id' => $interview->id,
            'interviewer_user_id' => $userId,
            'overall_score' => 80,
            'recommendation' => 'yes',
            'submitted_at' => now(),
        ]);
    }
    HrReferenceCheck::query()->create([
        'application_id' => $application->id,
        'referee_name' => 'Reference Person',
        'referee_relationship' => 'Manager',
        'status' => 'requested',
        'requested_at' => now(),
    ]);

    return compact('candidate', 'application', 'interview');
}

test('force expiry requires a reason and leaves the unanswered offer unchanged when omitted', function () {
    ['offer' => $offer, 'token' => $token] = deferredSentOffer($this->hr, $this->site);

    $this->actingAs($this->hr)
        ->post("/hr/recruitment/offers/{$offer->id}/expire", [])
        ->assertSessionHasErrors('reason');

    expect($offer->fresh()->candidate_portal_token)->toBe($token)
        ->and($offer->fresh()->portal_expires_at->isFuture())->toBeTrue();
});

test('force expiry invalidates the portal immediately and records actor reason and audit', function () {
    ['offer' => $offer, 'token' => $token] = deferredSentOffer($this->hr, $this->site);

    $this->actingAs($this->hr)
        ->post("/hr/recruitment/offers/{$offer->id}/expire", [
            'reason' => 'Candidate requested more time before a revised package.',
        ])
        ->assertSessionHas('success');

    $offer->refresh();
    expect($offer->candidate_portal_token)->toBeNull()
        ->and($offer->portal_expires_at->isPast())->toBeTrue()
        ->and($offer->expired_by)->toBe($this->hr->id)
        ->and($offer->expiry_reason)->toBe('Candidate requested more time before a revised package.');

    $this->get(route('careers.offer.show', ['token' => $token]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('valid', false));

    $audit = AuditLog::query()->where('action', 'recruitment.offer_force_expired')->latest('id')->first();
    expect($audit)->not->toBeNull()
        ->and($audit->organization_id)->toBe(1)
        ->and($audit->user_id)->toBe($this->hr->id)
        ->and($audit->meta['reason'])->toBe('Candidate requested more time before a revised package.');
});

test('accepted and declined offers cannot be force expired', function (string $response) {
    ['offer' => $offer, 'token' => $token] = deferredSentOffer($this->hr, $this->site, $response);

    $this->actingAs($this->hr)
        ->post("/hr/recruitment/offers/{$offer->id}/expire", ['reason' => 'Should not apply'])
        ->assertSessionHas('error');

    expect($offer->fresh()->candidate_portal_token)->toBe($token)
        ->and($offer->fresh()->response)->toBe($response);
})->with(['accepted', 'declined']);

test('resend is the intentional revival path and rotates the portal token', function () {
    Notification::fake();
    ['offer' => $offer, 'token' => $oldToken] = deferredSentOffer($this->hr, $this->site);

    $this->actingAs($this->hr)
        ->post("/hr/recruitment/offers/{$offer->id}/expire", ['reason' => 'Terms need revision'])
        ->assertSessionHas('success');
    $this->actingAs($this->hr)
        ->post("/hr/recruitment/offers/{$offer->id}/resend")
        ->assertSessionHas('success');

    $offer->refresh();
    expect($offer->candidate_portal_token)->not->toBeNull()
        ->and($offer->candidate_portal_token)->not->toBe($oldToken)
        ->and($offer->portal_expires_at->isFuture())->toBeTrue()
        ->and($offer->expired_by)->toBeNull()
        ->and($offer->expiry_reason)->toBeNull();
});

test('full interviewer scorecard quorum permits advancement beyond interview', function () {
    $second = User::factory()->create(['organization_id' => 1, 'approved_at' => now()]);
    ['candidate' => $candidate] = deferredCompletedPanel(
        $this->hr,
        [$this->hr->id, $second->id],
        [$this->hr->id, $second->id],
    );

    app(RecruitmentService::class)->advanceStage($candidate, 'reference_check', $this->hr->id);

    expect($candidate->fresh()->status)->toBe('reference_check');
});

test('zero interviewers and missing scorecards block advancement', function (string $scenario) {
    $second = $scenario === 'missing'
        ? User::factory()->create(['organization_id' => 1, 'approved_at' => now()])
        : null;
    $interviewers = $second ? [$this->hr->id, $second->id] : [];
    $submittedBy = $second ? [$this->hr->id] : [];

    ['application' => $application, 'candidate' => $candidate] = deferredCompletedPanel(
        $this->hr,
        $interviewers,
        $submittedBy,
    );

    $this->actingAs($this->hr)
        ->post("/hr/recruitment/applications/{$application->id}/advance", [
            'target_stage' => 'reference_check',
        ])
        ->assertSessionHas('error');

    expect($candidate->fresh()->status)->toBe('interview_completed');
})->with([
    'no assigned interviewers' => 'zero',
    'one missing panel score' => 'missing',
]);

test('scorecard override requires a non-empty reason and is canonically audited', function () {
    $second = User::factory()->create(['organization_id' => 1, 'approved_at' => now()]);
    ['application' => $application, 'candidate' => $candidate, 'interview' => $interview] = deferredCompletedPanel(
        $this->hr,
        [$this->hr->id, $second->id],
        [$this->hr->id],
    );

    $this->actingAs($this->hr)
        ->post("/hr/recruitment/applications/{$application->id}/advance", [
            'target_stage' => 'reference_check',
            'scorecard_override_reason' => '',
        ])
        ->assertSessionHas('error');
    expect($candidate->fresh()->status)->toBe('interview_completed');

    $this->actingAs($this->hr)
        ->post("/hr/recruitment/applications/{$application->id}/advance", [
            'target_stage' => 'reference_check',
            'scorecard_override_reason' => 'Panel member left the organisation before submitting.',
        ])
        ->assertSessionHas('success');

    expect($candidate->fresh()->status)->toBe('reference_check');
    $audit = AuditLog::query()->where('action', 'recruitment.scorecard_quorum_overridden')->latest('id')->first();
    expect($audit)->not->toBeNull()
        ->and($audit->organization_id)->toBe(1)
        ->and($audit->user_id)->toBe($this->hr->id)
        ->and($audit->meta['interview_id'])->toBe($interview->id)
        ->and($audit->meta['missing_interviewer_ids'])->toBe([$second->id])
        ->and($audit->meta['reason'])->toBe('Panel member left the organisation before submitting.');
});
