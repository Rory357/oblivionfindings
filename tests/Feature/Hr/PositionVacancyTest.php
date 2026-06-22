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
        'created_by' => 1,
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
        'status' => 'offer_sent',
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
