<?php

use App\Domain\Hr\Models\HrApplication;
use App\Domain\Hr\Models\HrCandidate;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrOffer;
use App\Domain\Hr\Models\HrOnboardingChecklist;
use App\Domain\Hr\Models\HrOnboardingTemplate;
use App\Domain\Hr\Notifications\NewHireWelcomeNotification;
use App\Domain\Hr\Services\RecruitmentService;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    $this->seed(RbacSeeder::class);

    $this->hr = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $this->hr->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'hr')->first()->id,
    ]);

    $this->site = Site::factory()->create(['type' => 'house']);
    HrEmployeeProfile::factory()->create([
        'user_id' => $this->hr->id,
        'primary_site_id' => $this->site->id,
        'secondary_site_ids' => [],
        'position_role' => 'hr',
        'is_active' => true,
        'start_date' => today()->subYear(),
        'end_date' => null,
    ]);
});

function seedOnboardingTemplate(int $hrId, string $role = 'support_worker'): void
{
    HrOnboardingTemplate::query()->create([
        'role' => $role,
        'site_type' => 'all',
        'tasks' => [
            ['category' => 'admin', 'title' => 'Sign contract', 'is_required' => true, 'sort_order' => 1],
        ],
        'is_active' => true,
        'created_by' => $hrId,
    ]);
}

function makeSentOffer(User $hr, Site $site, string $email, string $role = 'support_worker'): HrOffer
{
    $candidate = HrCandidate::factory()->create([
        'personal_email' => $email,
        'source' => 'direct',
        'status' => 'offer_sent',
        'created_by' => $hr->id,
    ]);

    $application = HrApplication::factory()->create([
        'candidate_id' => $candidate->id,
        'position_title' => 'Support Worker',
        'position_role' => $role,
        'target_site_id' => $site->id,
        'status' => 'offered',
    ]);

    return HrOffer::query()->create([
        'application_id' => $application->id,
        'position_title' => 'Support Worker',
        'position_role' => $role,
        'proposed_start_date' => now()->addWeek()->toDateString(),
        'employment_type' => 'full_time',
        'hours_per_week' => 40,
        'hourly_rate' => 30,
        'primary_site_id' => $site->id,
        'approval_status' => 'approved',
        'approved_by' => $hr->id,
        'approved_at' => now(),
        'sent_at' => now(),
        'created_by' => $hr->id,
    ]);
}

test('accepting an offer auto-converts to an employee and starts onboarding', function () {
    seedOnboardingTemplate($this->hr->id);
    $offer = makeSentOffer($this->hr, $this->site, 'flow.accept@example.test');
    $candidateId = $offer->application->candidate_id;

    $this->actingAs($this->hr)
        ->post("/hr/recruitment/offers/{$offer->id}/respond", ['response' => 'accepted'])
        ->assertSessionHas('success');

    $profile = HrEmployeeProfile::query()->where('candidate_id', $candidateId)->first();
    expect($profile)->not->toBeNull();
    expect($profile->is_active)->toBeTrue();

    expect(
        HrOnboardingChecklist::query()->where('employee_profile_id', $profile->id)->count()
    )->toBe(1);

    expect(HrCandidate::query()->find($candidateId)->status)->toBe('hired');
});

test('auto-onboarding is idempotent across a follow-up manual convert', function () {
    seedOnboardingTemplate($this->hr->id);
    $offer = makeSentOffer($this->hr, $this->site, 'flow.idempotent@example.test');
    $candidate = $offer->application->candidate;

    $this->actingAs($this->hr)
        ->post("/hr/recruitment/offers/{$offer->id}/respond", ['response' => 'accepted'])
        ->assertSessionHas('success');

    $profile = HrEmployeeProfile::query()->where('candidate_id', $candidate->id)->firstOrFail();

    // Re-entering via the allowed 'onboarding' window must not create a second
    // onboarding checklist (maybeGenerateOnboardingChecklist guard).
    $candidate->update(['status' => 'onboarding']);
    app(RecruitmentService::class)->convertToEmployee($candidate->fresh(), $offer->fresh(), $this->hr->id);

    expect(
        HrOnboardingChecklist::query()->where('employee_profile_id', $profile->id)->count()
    )->toBe(1);
});

test('accepting an offer still succeeds when no onboarding template matches', function () {
    // No template seeded for this role.
    $offer = makeSentOffer($this->hr, $this->site, 'flow.notemplate@example.test', 'coordinator');
    $candidateId = $offer->application->candidate_id;

    $this->actingAs($this->hr)
        ->post("/hr/recruitment/offers/{$offer->id}/respond", ['response' => 'accepted'])
        ->assertSessionHas('success');

    $profile = HrEmployeeProfile::query()->where('candidate_id', $candidateId)->first();
    expect($profile)->not->toBeNull();
    expect(
        HrOnboardingChecklist::query()->where('employee_profile_id', $profile->id)->count()
    )->toBe(0);
});

/*
 * Seam S12 — Recruitment → Onboarding. The profile + onboarding-checklist halves
 * of the auto-convert chain are proven above; these close the remaining two
 * downstream effects the seam contract promises ("provision the employee +
 * start onboarding + welcome them"):
 *   (a) a User login is minted for the new hire (EmployeeIntakeService::intake), and
 *   (d) the branded NewHireWelcomeNotification reaches the candidate's personal
 *       inbox on the AUTO-ACCEPT path — the primary flow — not only via the manual
 *       Convert action (F-77: sendNewHireWelcome was wired into CandidateController
 *       ::convertToEmployee but not the respondOffer accept branch, so an accepted
 *       candidate was provisioned + onboarded but never welcomed).
 */
test('accepting an offer mints the new-hire user login (User door of the intake seam)', function () {
    seedOnboardingTemplate($this->hr->id);
    $email = 'flow.userlogin@example.test';
    $offer = makeSentOffer($this->hr, $this->site, $email);

    $this->actingAs($this->hr)
        ->post("/hr/recruitment/offers/{$offer->id}/respond", ['response' => 'accepted'])
        ->assertSessionHas('success');

    // The work email defaults to the candidate's personal email (offer carries no
    // work_email), so the provisioned login is keyed on it.
    $user = User::query()->where('email', $email)->first();
    expect($user)->not->toBeNull();
    expect($user->role)->toBe('support_worker');
    expect($user->approved_at)->not->toBeNull();

    // …and the profile links back to that very user (one intake, one login).
    $profile = HrEmployeeProfile::query()->where('candidate_id', $offer->application->candidate_id)->firstOrFail();
    expect($profile->user_id)->toBe($user->id);
});

test('accepting an offer sends the branded new-hire welcome on the auto-accept path (F-77)', function () {
    seedOnboardingTemplate($this->hr->id);
    $email = 'flow.welcome@example.test';
    $offer = makeSentOffer($this->hr, $this->site, $email);

    $this->actingAs($this->hr)
        ->post("/hr/recruitment/offers/{$offer->id}/respond", ['response' => 'accepted'])
        ->assertSessionHas('success');

    // The welcome is an on-demand mail notification routed to the candidate's
    // personal inbox — proven sent from the accept branch itself, not the manual
    // Convert action (which the auto-accept flow never reaches).
    Notification::assertSentOnDemand(
        NewHireWelcomeNotification::class,
        fn ($notification, $channels, $notifiable) => ($notifiable->routes['mail'] ?? null) === $email,
    );
});
