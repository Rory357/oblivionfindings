<?php

use App\Models\Client;
use App\Models\MedicationCompetencyAssessment;
use App\Models\MedicationCompetencyExemption;
use App\Models\Permission;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Services\ShiftStaffEligibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeMedicationShift(Site $site, Client $client): Shift
{
    // The med_competent coverage role is what makes CoverageRoleService (and
    // therefore MedicationCompetencyRule) require a current competency.
    return Shift::factory()->create([
        'client_id' => $client->id,
        'site_id' => $site->id,
        'service_context_id' => ServiceContext::factory()->create()->id,
        'user_id' => User::factory(),
        'starts_at' => now()->addDay()->setTime(9, 0),
        'ends_at' => now()->addDay()->setTime(17, 0),
        'status' => 'scheduled',
        'coverage_roles' => ['med_competent'],
        'created_by' => User::factory(),
    ]);
}

test('an expired medication competency blocks a medication shift', function () {
    $site = Site::factory()->create();
    $client = Client::factory()->create(['site_id' => $site->id]);
    $shift = makeMedicationShift($site, $client);

    $staff = User::factory()->create();
    MedicationCompetencyAssessment::create([
        'user_id' => $staff->id,
        'assessment_type' => 'annual',
        'status' => 'passed',
        'assessment_date' => now()->subYear()->toDateString(),
        'expiry_date' => now()->subDay()->toDateString(),
    ]);

    $result = app(ShiftStaffEligibilityService::class)->evaluate($shift, $staff)->toArray();

    expect($result['is_eligible'])->toBeFalse();
    expect(collect($result['blocked_reasons'])->implode(' '))->toContain('Medication competency expired');
});

test('a current medication competency passes the medication-competency rule', function () {
    $site = Site::factory()->create();
    $client = Client::factory()->create(['site_id' => $site->id]);
    $shift = makeMedicationShift($site, $client);

    $staff = User::factory()->create();
    MedicationCompetencyAssessment::create([
        'user_id' => $staff->id,
        'assessment_type' => 'annual',
        'status' => 'passed',
        'assessment_date' => now()->subMonth()->toDateString(),
        'expiry_date' => now()->addYear()->toDateString(),
    ]);

    $result = app(ShiftStaffEligibilityService::class)->evaluate($shift, $staff)->toArray();

    expect(collect($result['blocked_reasons'])->implode(' '))->not->toContain('Medication competency');
    expect(collect($result['warning_reasons'])->implode(' '))->not->toContain('Medication competency expires');
});

test('a competency expiring within the warning window warns but does not block', function () {
    $site = Site::factory()->create();
    $client = Client::factory()->create(['site_id' => $site->id]);
    $shift = makeMedicationShift($site, $client);

    $staff = User::factory()->create();
    MedicationCompetencyAssessment::create([
        'user_id' => $staff->id,
        'assessment_type' => 'annual',
        'status' => 'passed',
        'assessment_date' => now()->subYear()->toDateString(),
        'expiry_date' => now()->addDays(10)->toDateString(),
    ]);

    $result = app(ShiftStaffEligibilityService::class)->evaluate($shift, $staff)->toArray();

    expect(collect($result['blocked_reasons'])->implode(' '))->not->toContain('Medication competency expired');
    expect(collect($result['warning_reasons'])->implode(' '))->toContain('Medication competency expires');
});

test('medication permission alone does not satisfy medication coverage eligibility', function () {
    $site = Site::factory()->create();
    $client = Client::factory()->create(['site_id' => $site->id]);
    $shift = makeMedicationShift($site, $client);
    $staff = User::factory()->create();
    $permission = Permission::query()->firstOrCreate(
        ['key' => 'medications.administer.record'],
        [
            'description' => 'Record medication administrations',
            'group' => 'medications',
            'module' => 'Clinical',
        ],
    );
    $staff->permissionOverrides()->attach($permission->id, ['allowed' => true]);

    $result = app(ShiftStaffEligibilityService::class)->evaluate($shift, $staff)->toArray();
    $rule = collect($result['checked_rules'])->firstWhere('rule', 'medication_competency');

    expect($staff->canDo('medications.administer.record'))->toBeTrue()
        ->and($rule['passed'])->toBeFalse()
        ->and($rule['overrideable'])->toBeFalse()
        ->and($rule['competency_state'])->toBe('unassessed');
});

test('a passed medication competency with no expiry blocks medication coverage eligibility', function () {
    $site = Site::factory()->create();
    $client = Client::factory()->create(['site_id' => $site->id]);
    $shift = makeMedicationShift($site, $client);
    $staff = User::factory()->create();
    MedicationCompetencyAssessment::create([
        'user_id' => $staff->id,
        'assessment_type' => 'annual',
        'status' => 'passed',
        'assessment_date' => now()->toDateString(),
        'expiry_date' => null,
    ]);

    $result = app(ShiftStaffEligibilityService::class)->evaluate($shift, $staff)->toArray();
    $rule = collect($result['checked_rules'])->firstWhere('rule', 'medication_competency');

    expect($rule['passed'])->toBeFalse()
        ->and($rule['overrideable'])->toBeFalse()
        ->and($rule['competency_state'])->toBe('missing_expiry');
});

test('a failed medication competency blocks medication coverage eligibility', function () {
    $site = Site::factory()->create();
    $client = Client::factory()->create(['site_id' => $site->id]);
    $shift = makeMedicationShift($site, $client);
    $staff = User::factory()->create();
    MedicationCompetencyAssessment::create([
        'user_id' => $staff->id,
        'assessment_type' => 'initial',
        'status' => 'failed',
        'assessment_date' => now()->toDateString(),
        'expiry_date' => now()->addYear()->toDateString(),
    ]);

    $result = app(ShiftStaffEligibilityService::class)->evaluate($shift, $staff)->toArray();
    $rule = collect($result['checked_rules'])->firstWhere('rule', 'medication_competency');

    expect($rule['passed'])->toBeFalse()
        ->and($rule['overrideable'])->toBeFalse()
        ->and($rule['competency_state'])->toBe('failed');
});

test('a finite explicit site exemption satisfies the same medication coverage policy', function () {
    $site = Site::factory()->create();
    $otherSite = Site::factory()->create();
    $client = Client::factory()->create(['site_id' => $site->id]);
    $shift = makeMedicationShift($site, $client);
    $staff = User::factory()->create();
    $approver = User::factory()->create();

    $exemption = MedicationCompetencyExemption::create([
        'user_id' => $staff->id,
        'site_id' => $site->id,
        'scope' => MedicationCompetencyExemption::SCOPE_ADMINISTRATION,
        'reason' => 'Short-term supervised operational coverage approved after clinical review.',
        'approved_by' => $approver->id,
        'approved_at' => now(),
        'starts_at' => now(),
        'expires_at' => now()->addWeek(),
    ]);

    $result = app(ShiftStaffEligibilityService::class)->evaluate($shift, $staff)->toArray();
    $rule = collect($result['checked_rules'])->firstWhere('rule', 'medication_competency');

    expect($rule['competency_state'])->toBe('exempt')
        ->and($rule['exemption_id'])->toBe($exemption->id)
        ->and(collect($result['blocked_reasons'])->implode(' '))->not->toContain('medication competency');

    $otherClient = Client::factory()->create(['site_id' => $otherSite->id]);
    $otherShift = makeMedicationShift($otherSite, $otherClient);
    $otherResult = app(ShiftStaffEligibilityService::class)->evaluate($otherShift, $staff)->toArray();
    $otherRule = collect($otherResult['checked_rules'])->firstWhere('rule', 'medication_competency');

    expect($otherRule['passed'])->toBeFalse()
        ->and($otherRule['competency_state'])->toBe('unassessed');
});
