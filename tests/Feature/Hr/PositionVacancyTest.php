<?php

use App\Domain\Hr\Models\HrApplication;
use App\Domain\Hr\Models\HrCandidate;
use App\Domain\Hr\Models\HrJobRequisition;
use App\Domain\Hr\Models\HrOffer;
use App\Domain\Hr\Models\HrPosition;
use App\Domain\Hr\Services\EmployeeIntakeService;
use App\Domain\Hr\Services\RecruitmentService;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    $this->seed(RbacSeeder::class);
    $this->actor = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
    // canDo() resolves via the Spatie role relation, not the role string column.
    $adminRole = Role::query()->where('name', 'admin')->first();
    if ($adminRole) {
        $this->actor->roles()->syncWithoutDetaching([$adminRole->id]);
    }
});

function makePosition(int $budget = 2): HrPosition
{
    return HrPosition::query()->create([
        'tenant_id' => 1,
        'title' => 'Support Worker ' . fake()->unique()->numerify('###'),
        'code' => 'POS-' . fake()->unique()->numerify('#####'),
        'employment_type' => 'full_time',
        'fte' => 1.0,
        'headcount_budget' => $budget,
        'current_headcount' => 0,
        'is_active' => true,
        // created_by has a FK to users; use a real one (the seeded/actor user)
        // rather than a hard-coded id that may not exist in a fresh DB.
        'created_by' => User::query()->value('id'),
    ]);
}

function hireInto(HrPosition $pos, int $actorId, bool $active = true): void
{
    app(EmployeeIntakeService::class)->intake(
        'Hire ' . fake()->unique()->name(),
        fake()->unique()->safeEmail(),
        'support_worker',
        [
            'position_id' => $pos->id,
            'position_title' => 'Support Worker',
            'position_role' => 'support_worker',
            'employment_type' => 'full_time',
            'start_date' => now()->toDateString(),
        ],
        $actorId,
        1,
        false, // onboarding off — isolate headcount behaviour
        false,
    );
}

test('hiring into a position syncs current_headcount via the observer', function () {
    $pos = makePosition(2);
    expect($pos->current_headcount)->toBe(0);

    hireInto($pos, $this->actor->id);

    expect($pos->fresh()->current_headcount)->toBe(1)
        ->and($pos->fresh()->vacancies)->toBe(1)
        ->and($pos->fresh()->is_understaffed)->toBeTrue();
});

test('actionable vacancies subtract open requisition openings', function () {
    $pos = makePosition(2);
    hireInto($pos, $this->actor->id); // current 1, vacancies 1

    HrJobRequisition::query()->create([
        'tenant_id' => 1,
        'title' => 'Req',
        'slug' => 'req-' . fake()->unique()->numerify('#####'),
        'position_id' => $pos->id,
        'employment_type' => 'full_time',
        'openings' => 1,
        'status' => 'published',
        'description' => 'x',
        'created_by' => $this->actor->id,
        'updated_by' => $this->actor->id,
    ]);

    $pos->refresh();
    expect($pos->open_requisition_openings)->toBe(1)
        ->and($pos->actionable_vacancies)->toBe(0)   // 2 - 1 filled - 1 in recruitment
        ->and($pos->is_understaffed)->toBeFalse();
});

test('a closed requisition no longer counts against actionable vacancies', function () {
    $pos = makePosition(1);

    HrJobRequisition::query()->create([
        'tenant_id' => 1, 'title' => 'Req', 'slug' => 'req-' . fake()->unique()->numerify('#####'),
        'position_id' => $pos->id, 'employment_type' => 'full_time', 'openings' => 1,
        'status' => 'closed', 'description' => 'x',
        'created_by' => $this->actor->id, 'updated_by' => $this->actor->id,
    ]);

    expect($pos->fresh()->open_requisition_openings)->toBe(0)
        ->and($pos->fresh()->actionable_vacancies)->toBe(1);
});

test('converting an offer with a position fills that position', function () {
    $site = Site::factory()->create(['tenant_id' => 1, 'type' => 'house']);
    $pos = makePosition(2);

    $candidate = HrCandidate::factory()->create([
        'tenant_id' => 1,
        'personal_email' => fake()->unique()->safeEmail(),
        'status' => 'offer_accepted', // convertToEmployee requires accepted/onboarding
        'created_by' => $this->actor->id,
    ]);
    $application = HrApplication::factory()->create([
        'tenant_id' => 1,
        'candidate_id' => $candidate->id,
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'target_site_id' => $site->id,
        'status' => 'offered',
    ]);
    $offer = HrOffer::query()->create([
        'application_id' => $application->id,
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'position_id' => $pos->id,
        'proposed_start_date' => now()->addWeek()->toDateString(),
        'employment_type' => 'full_time',
        'primary_site_id' => $site->id,
        'approval_status' => 'approved',
        'response' => 'accepted',
        'created_by' => $this->actor->id,
    ]);

    $profile = app(RecruitmentService::class)->convertToEmployee($candidate->fresh(), $offer->fresh(), $this->actor->id);

    expect((int) $profile->position_id)->toBe($pos->id)
        ->and($pos->fresh()->current_headcount)->toBe(1);
});

test('creating a position with open_requisition opens a linked draft requisition', function () {
    $code = 'NRN-' . fake()->unique()->numerify('####');

    $this->actingAs($this->actor)->post('/hr/positions', [
        'title' => 'Night RN',
        'code' => $code,
        'employment_type' => 'full_time',
        'fte' => 1.0,
        'headcount_budget' => 3,
        'summary' => 'Overnight registered nurse.',
        'open_requisition' => true,
    ])->assertRedirect();

    $position = HrPosition::query()->where('code', $code)->firstOrFail();
    $req = HrJobRequisition::query()->where('position_id', $position->id)->first();

    expect($req)->not->toBeNull()
        ->and($req->openings)->toBe(3)
        ->and($req->status)->toBe('draft')
        ->and($req->title)->toBe('Night RN');
});

test('creating a position without the toggle opens no requisition', function () {
    $code = 'DRN-' . fake()->unique()->numerify('####');

    $this->actingAs($this->actor)->post('/hr/positions', [
        'title' => 'Day RN',
        'code' => $code,
        'employment_type' => 'full_time',
        'fte' => 1.0,
        'headcount_budget' => 2,
    ])->assertRedirect();

    $position = HrPosition::query()->where('code', $code)->firstOrFail();
    expect(HrJobRequisition::query()->where('position_id', $position->id)->exists())->toBeFalse();
});

test('hr:check-vacancies reconciles stored headcount drift', function () {
    $pos = makePosition(2);
    hireInto($pos, $this->actor->id); // observer sets current_headcount = 1

    // Simulate a mass update that bypassed the observer.
    DB::table('hr_positions')->where('id', $pos->id)->update(['current_headcount' => 99]);

    $this->artisan('hr:check-vacancies')->assertExitCode(0);

    expect($pos->fresh()->current_headcount)->toBe(1);
});

/* --- 3d: recruitment loop-close ----------------------------------------- */

function openReqFor(HrPosition $pos, int $openings, int $actorId, string $status = 'published'): HrJobRequisition
{
    return HrJobRequisition::query()->create([
        'tenant_id' => 1,
        'title' => 'Req ' . fake()->unique()->numerify('###'),
        'slug' => 'req-' . fake()->unique()->numerify('#####'),
        'position_id' => $pos->id,
        'employment_type' => 'full_time',
        'openings' => $openings,
        'status' => $status,
        'description' => 'x',
        'created_by' => $actorId,
        'updated_by' => $actorId,
    ]);
}

test('filling a position auto-closes its open requisition (loop close)', function () {
    $pos = makePosition(1);
    $req = openReqFor($pos, 1, $this->actor->id);

    hireInto($pos, $this->actor->id); // fills the only seat → observer → close loop

    expect($pos->fresh()->current_headcount)->toBe(1)
        ->and($req->fresh()->status)->toBe('closed')
        ->and($req->fresh()->closing_at)->not->toBeNull();
});

test('a part-filled position keeps its requisition open', function () {
    $pos = makePosition(2);
    $req = openReqFor($pos, 2, $this->actor->id);

    hireInto($pos, $this->actor->id); // 1 of 2 — gap remains

    expect($pos->fresh()->current_headcount)->toBe(1)
        ->and($req->fresh()->status)->toBe('published');
});

test('attrition below budget does not reopen a closed requisition', function () {
    $pos = makePosition(1);
    $req = openReqFor($pos, 1, $this->actor->id);
    hireInto($pos, $this->actor->id);
    expect($req->fresh()->status)->toBe('closed');

    // Deactivate the only employee → position understaffed again.
    $pos->employees()->update(['is_active' => false]);
    app(\App\Domain\Hr\Services\PositionService::class)->syncHeadcount($pos->id);

    expect($pos->fresh()->current_headcount)->toBe(0)
        ->and($req->fresh()->status)->toBe('closed'); // one-way: stays closed
});

test('hr:check-vacancies closes requisitions for positions filled via bulk paths', function () {
    $pos = makePosition(1);
    $req = openReqFor($pos, 1, $this->actor->id);

    // Fill the seat WITHOUT firing the observer (mimics a mass bulk update).
    $u = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    \App\Domain\Hr\Models\HrEmployeeProfile::withoutEvents(function () use ($u, $pos) {
        \App\Domain\Hr\Models\HrEmployeeProfile::query()->create([
            'tenant_id' => 1,
            'user_id' => $u->id,
            'employee_number' => 'EMP-' . $u->id,
            'work_email' => $u->email,
            'position_id' => $pos->id,
            'position_title' => 'Support Worker',
            'position_role' => 'support_worker',
            'employment_type' => 'full_time',
            'start_date' => now()->subMonth()->toDateString(),
            'is_active' => true,
        ]);
    });

    expect($req->fresh()->status)->toBe('published'); // observer bypassed

    $this->artisan('hr:check-vacancies')->assertExitCode(0);

    expect($pos->fresh()->current_headcount)->toBe(1)
        ->and($req->fresh()->status)->toBe('closed');
});
