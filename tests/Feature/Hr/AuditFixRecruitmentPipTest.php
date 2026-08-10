<?php

use App\Domain\Hr\Models\HrApplication;
use App\Domain\Hr\Models\HrCandidate;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrJobRequisition;
use App\Domain\Hr\Models\HrOffer;
use App\Domain\Hr\Models\HrOnboardingTemplate;
use App\Domain\Hr\Models\HrPerformanceImprovementPlan;
use App\Domain\Hr\Notifications\OfferDeclinedNotification;
use App\Domain\Hr\Notifications\PipCreatedNotification;
use App\Domain\Hr\Notifications\RejectionNotification;
use App\Domain\Hr\Services\RecruitmentService;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(RbacSeeder::class);

    $this->hr = User::factory()->create([
        'role' => 'hr',
        'approved_at' => now(),
    ]);

    $role = Role::where('name', 'hr')->first();
    if ($role) {
        $this->hr->roles()->syncWithoutDetaching([$role->id]);
    }

    $this->site = Site::factory()->create([
        'type' => 'house',
    ]);
    auditFixPerformanceProfile($this->hr, $this->site);

    HrOnboardingTemplate::query()->create([
        'role' => 'support_worker',
        'site_type' => 'all',
        'tasks' => [],
        'is_active' => true,
        'created_by' => $this->hr->id,
    ]);
});

/* ------------------------------------------------------------------ */
/*  Fixtures */
/* ------------------------------------------------------------------ */

function auditFixRequisition(User $hiringManager, User $creator, int $openings = 1, string $status = 'published'): HrJobRequisition
{
    if (! $hiringManager->hrEmployeeProfile()->exists()) {
        auditFixPerformanceProfile($hiringManager, test()->site);
    }

    return HrJobRequisition::query()->create([
        'title' => 'Support Worker — Audit Fixture',
        'slug' => 'support-worker-audit-fixture-'.uniqid(),
        'position_role' => 'support_worker',
        'site_id' => test()->site->id,
        'employment_type' => 'full_time',
        'openings' => $openings,
        'status' => $status,
        'hiring_manager_user_id' => $hiringManager->id,
        'published_at' => now(),
        'created_by' => $creator->id,
    ]);
}

function auditFixSentOffer(User $hr, Site $site, HrJobRequisition $requisition, string $email): array
{
    $candidate = HrCandidate::factory()->create([
        'first_name' => 'Aroha',
        'last_name' => 'Candidate',
        'personal_email' => $email,
        'source' => 'direct',
        'status' => 'offer_sent',
        'created_by' => $hr->id,
    ]);

    $application = HrApplication::factory()->create([
        'candidate_id' => $candidate->id,
        'requisition_id' => $requisition->id,
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'target_site_id' => $site->id,
        'status' => 'offered',
    ]);

    $offer = HrOffer::query()->create([
        'application_id' => $application->id,
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'proposed_start_date' => now()->addWeek()->toDateString(),
        'employment_type' => 'full_time',
        'hours_per_week' => 40,
        'hourly_rate' => 30,
        'primary_site_id' => $site->id,
        'approval_status' => 'approved',
        'approved_by' => $hr->id,
        'approved_at' => now(),
        'sent_at' => now()->subDay(),
        'created_by' => $hr->id,
    ]);

    return [$candidate, $application, $offer];
}

/* ------------------------------------------------------------------ */
/*  1. Offer declined → hiring manager notified */
/* ------------------------------------------------------------------ */

test('declining an offer notifies the hiring manager', function () {
    Notification::fake();

    $hiringManager = User::factory()->create(['role' => 'team_lead', 'approved_at' => now()]);
    $requisition = auditFixRequisition($hiringManager, $this->hr);
    [$candidate, $application, $offer] = auditFixSentOffer($this->hr, $this->site, $requisition, 'aroha.decline@example.test');

    $this->actingAs($this->hr)
        ->post("/hr/recruitment/offers/{$offer->id}/respond", [
            'response' => 'declined',
            'response_notes' => 'Accepted another role closer to home',
        ])
        ->assertSessionHas('success');

    Notification::assertSentTo(
        $hiringManager,
        OfferDeclinedNotification::class,
        function (OfferDeclinedNotification $notification) use ($hiringManager, $candidate) {
            $data = $notification->toArray($hiringManager);

            return $data['reason'] === 'declined'
                && $data['candidate_id'] === $candidate->id
                && $data['decline_reason'] === 'Accepted another role closer to home';
        },
    );

    $offer->refresh();
    $candidate->refresh();
    expect($offer->response)->toBe('declined');
    expect($candidate->status)->toBe('rejected');
});

test('a withdrawn offer also notifies the hiring manager with reason withdrawn', function () {
    Notification::fake();

    $hiringManager = User::factory()->create(['role' => 'team_lead', 'approved_at' => now()]);
    $requisition = auditFixRequisition($hiringManager, $this->hr);
    [, , $offer] = auditFixSentOffer($this->hr, $this->site, $requisition, 'aroha.withdrawn@example.test');

    $this->actingAs($this->hr)
        ->post("/hr/recruitment/offers/{$offer->id}/respond", ['response' => 'withdrawn'])
        ->assertSessionHas('success');

    Notification::assertSentTo(
        $hiringManager,
        OfferDeclinedNotification::class,
        fn (OfferDeclinedNotification $n) => $n->toArray($hiringManager)['reason'] === 'withdrawn',
    );
});

/* ------------------------------------------------------------------ */
/*  2. Requisition auto-closes when every opening is filled */
/* ------------------------------------------------------------------ */

test('requisition closes automatically when the hired count reaches openings', function () {
    Notification::fake();

    $hiringManager = User::factory()->create(['role' => 'team_lead', 'approved_at' => now()]);
    $requisition = auditFixRequisition($hiringManager, $this->hr, openings: 1, status: 'published');
    [$candidate, , $offer] = auditFixSentOffer($this->hr, $this->site, $requisition, 'aroha.hired@example.test');

    $candidate->update(['status' => 'offer_accepted']);
    $offer->update(['response' => 'accepted', 'response_at' => now()]);

    app(RecruitmentService::class)->convertToEmployee($candidate->fresh(), $offer->fresh(), $this->hr->id);

    expect($requisition->fresh()->status)->toBe('closed');
});

test('requisition stays open while openings remain unfilled', function () {
    Notification::fake();

    $hiringManager = User::factory()->create(['role' => 'team_lead', 'approved_at' => now()]);
    $requisition = auditFixRequisition($hiringManager, $this->hr, openings: 2, status: 'published');
    [$candidate, , $offer] = auditFixSentOffer($this->hr, $this->site, $requisition, 'aroha.first-of-two@example.test');

    $candidate->update(['status' => 'offer_accepted']);
    $offer->update(['response' => 'accepted', 'response_at' => now()]);

    app(RecruitmentService::class)->convertToEmployee($candidate->fresh(), $offer->fresh(), $this->hr->id);

    expect($requisition->fresh()->status)->toBe('published');
});

/* ------------------------------------------------------------------ */
/*  3. Bulk reject → optional decline emails */
/* ------------------------------------------------------------------ */

test('bulk reject sends a decline email per candidate when opted in', function () {
    Notification::fake();

    $candidates = collect(['bulk.one@example.test', 'bulk.two@example.test'])->map(function ($email) {
        $candidate = HrCandidate::factory()->create([
            'personal_email' => $email,
            'status' => 'screening',
            'created_by' => $this->hr->id,
        ]);
        HrApplication::factory()->create([
            'candidate_id' => $candidate->id,
            'position_title' => 'Support Worker',
            'position_role' => 'support_worker',
            'target_site_id' => $this->site->id,
            'status' => 'active',
        ]);

        return $candidate;
    });

    $this->actingAs($this->hr)
        ->post('/hr/recruitment/applications/bulk', [
            'action' => 'reject',
            'candidate_ids' => $candidates->pluck('id')->all(),
            'reason' => 'Position filled',
            'send_decline_email' => true,
            'decline_message' => 'We encourage you to apply for future roles.',
        ])
        ->assertSessionHas('success');

    Notification::assertSentOnDemandTimes(RejectionNotification::class, 2);

    foreach ($candidates as $candidate) {
        expect($candidate->fresh()->status)->toBe('rejected');
    }
});

test('bulk reject sends no decline email by default', function () {
    Notification::fake();

    $candidate = HrCandidate::factory()->create([
        'personal_email' => 'bulk.silent@example.test',
        'status' => 'screening',
        'created_by' => $this->hr->id,
    ]);
    HrApplication::factory()->create([
        'candidate_id' => $candidate->id,
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'target_site_id' => $this->site->id,
        'status' => 'active',
    ]);

    $this->actingAs($this->hr)
        ->post('/hr/recruitment/applications/bulk', [
            'action' => 'reject',
            'candidate_ids' => [$candidate->id],
            'reason' => 'Position filled',
        ])
        ->assertSessionHas('success');

    Notification::assertSentOnDemandTimes(RejectionNotification::class, 0);
});

/* ------------------------------------------------------------------ */
/*  4. PIP creation → employee + manager notified */
/* ------------------------------------------------------------------ */

function auditFixPipPayload(User $employee): array
{
    return [
        'employee_user_id' => $employee->id,
        'title' => 'Support plan — documentation quality',
        'reason' => 'Progress notes repeatedly missing required detail.',
        'expectations' => 'All notes complete and submitted before end of shift.',
        'support_offered' => 'Weekly 1:1 coaching with team lead.',
        'start_date' => now()->toDateString(),
        'end_date' => now()->addWeeks(6)->toDateString(),
        'milestones' => [
            ['title' => 'Week 2 review', 'due_date' => now()->addWeeks(2)->toDateString()],
        ],
    ];
}

function auditFixPerformanceProfile(User $user, Site $site): HrEmployeeProfile
{
    return HrEmployeeProfile::factory()->create([
        'user_id' => $user->id,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        'start_date' => today()->subYear(),
        'end_date' => null,
        'is_active' => true,
    ]);
}

test('creating a PIP notifies the subject employee and the manager', function () {
    Notification::fake();

    $employee = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    auditFixPerformanceProfile($employee, $this->site);

    $this->actingAs($this->hr)
        ->post('/hr/performance/pips', auditFixPipPayload($employee))
        ->assertRedirect();

    $pip = HrPerformanceImprovementPlan::query()->where('employee_user_id', $employee->id)->firstOrFail();

    Notification::assertSentTo(
        $employee,
        PipCreatedNotification::class,
        fn (PipCreatedNotification $n) => $n->toArray($employee)['pip_id'] === $pip->id
            && $n->toArray($employee)['for_subject'] === true,
    );
    Notification::assertSentTo(
        $this->hr,
        PipCreatedNotification::class,
        fn (PipCreatedNotification $n) => $n->toArray($this->hr)['for_subject'] === false,
    );
});

/* ------------------------------------------------------------------ */
/*  5. Subject can view own PIP; strangers cannot */
/* ------------------------------------------------------------------ */

test('the subject employee can view their own PIP read-only and a stranger gets 404', function () {
    Notification::fake();

    $employee = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $stranger = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    auditFixPerformanceProfile($employee, $this->site);
    auditFixPerformanceProfile($stranger, $this->site);

    $this->actingAs($this->hr)
        ->post('/hr/performance/pips', auditFixPipPayload($employee))
        ->assertRedirect();

    $pip = HrPerformanceImprovementPlan::query()->where('employee_user_id', $employee->id)->firstOrFail();

    $this->actingAs($employee)
        ->get("/hr/performance/pips/{$pip->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('hr/performance/pips/show')
            ->where('viewer_is_subject', true)
            ->where('can.manage', false));

    $this->actingAs($stranger)
        ->get("/hr/performance/pips/{$pip->id}")
        ->assertNotFound();
});

test('the subject employee can acknowledge their own PIP', function () {
    Notification::fake();

    $employee = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    auditFixPerformanceProfile($employee, $this->site);

    $this->actingAs($this->hr)
        ->post('/hr/performance/pips', auditFixPipPayload($employee))
        ->assertRedirect();

    $pip = HrPerformanceImprovementPlan::query()->where('employee_user_id', $employee->id)->firstOrFail();

    $this->actingAs($employee)
        ->post("/hr/performance/pips/{$pip->id}/acknowledge")
        ->assertSessionHas('success');

    expect($pip->fresh()->employee_acknowledged)->toBeTrue();
});

/* ------------------------------------------------------------------ */
/*  6. My-HR attention item for an unacknowledged PIP */
/* ------------------------------------------------------------------ */

test('an active unacknowledged PIP appears as a critical attention item on My HR', function () {
    Notification::fake();

    $employee = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    HrEmployeeProfile::query()->create([
        'user_id' => $employee->id,
        'employee_number' => 'EMP-PIP-'.$employee->id,
        'work_email' => 'pip'.$employee->id.'@example.test',
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'employment_type' => 'full_time',
        'start_date' => now()->subYear()->toDateString(),
        'is_active' => true,
        'primary_site_id' => $this->site->id,
        'secondary_site_ids' => [],
    ]);

    $this->actingAs($this->hr)
        ->post('/hr/performance/pips', auditFixPipPayload($employee))
        ->assertRedirect();

    $pip = HrPerformanceImprovementPlan::query()->where('employee_user_id', $employee->id)->firstOrFail();

    $this->actingAs($employee)
        ->get('/hr/my')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('hr/my/index')
            ->where('overview.attention.0.id', 'pip')
            ->where('overview.attention.0.tone', 'critical')
            ->where('overview.attention.0.href', "/hr/performance/pips/{$pip->id}"));

    // Acknowledged → the item disappears.
    $this->actingAs($employee)->post("/hr/performance/pips/{$pip->id}/acknowledge");

    $this->actingAs($employee)
        ->get('/hr/my')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('hr/my/index')
            ->missing('overview.attention.0'));
});
