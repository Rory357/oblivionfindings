<?php

use App\Domain\Hr\Models\HrComplianceMatrix;
use App\Domain\Hr\Models\HrComplianceRequirement;
use App\Domain\Hr\Models\HrStaffComplianceStatus;
use App\Domain\Hr\Services\ComplianceMatrixService;
use App\Models\Role;
use App\Models\StaffBackgroundCheck;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

/*
 * The nightly compliance sweep read a background check's `completed_at`,
 * a column the table never had, so EvaluateComplianceMatrixJob failed every
 * night. A police check's result date is its issue date (else its check date).
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-30 00:00:00', 'UTC'));
    $this->seed(RbacSeeder::class);
});

function policeCheckRequirementFor(User $staff): HrComplianceRequirement
{
    $role = Role::query()->where('name', 'support_worker')->firstOrFail();
    $staff->roles()->syncWithoutDetaching([$role->id]);

    $requirement = HrComplianceRequirement::query()->create([
        'code' => 'POLICE_VET',
        'name' => 'Police vetting',
        'category' => 'vetting',
        'check_type' => 'background_check',
        'validity_months' => 36,
        'renewal_reminder_days' => 60,
        'hard_stop' => true,
        'is_active' => true,
    ]);
    HrComplianceMatrix::query()->create([
        'requirement_id' => $requirement->id,
        'role' => 'support_worker',
        'site_type' => 'all',
        'is_mandatory' => true,
    ]);

    return $requirement;
}

test('the compliance sweep dates a police check from its latest issue date', function () {
    $staff = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $requirement = policeCheckRequirementFor($staff);

    StaffBackgroundCheck::query()->create([
        'user_id' => $staff->id,
        'check_type' => 'police_check',
        'status' => 'clear',
        'check_date' => '2022-01-05',
        'issue_date' => '2022-01-20',
    ]);
    $latest = StaffBackgroundCheck::query()->create([
        'user_id' => $staff->id,
        'check_type' => 'police_check',
        'status' => 'clear',
        'check_date' => '2025-03-01',
        'issue_date' => '2025-03-10',
    ]);

    app(ComplianceMatrixService::class)->evaluateStaff($staff);

    $status = HrStaffComplianceStatus::query()
        ->where('user_id', $staff->id)
        ->where('requirement_id', $requirement->id)
        ->sole();

    expect($status->status)->toBe('compliant')
        ->and($status->evidence_type)->toBe('background_check')
        ->and($status->evidence_id)->toBe($latest->id)
        ->and($status->valid_from?->toDateString())->toBe('2025-03-10')
        ->and($status->expires_at?->toDateString())->toBe('2028-03-10');
});

test('a police check with only a check date still counts, and expires from it', function () {
    $staff = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $requirement = policeCheckRequirementFor($staff);

    StaffBackgroundCheck::query()->create([
        'user_id' => $staff->id,
        'check_type' => 'police_check',
        'status' => 'clear',
        'check_date' => '2023-09-01',
        'issue_date' => null,
    ]);

    app(ComplianceMatrixService::class)->evaluateStaff($staff);

    $status = HrStaffComplianceStatus::query()
        ->where('user_id', $staff->id)
        ->where('requirement_id', $requirement->id)
        ->sole();

    expect($status->status)->toBe('expired')
        ->and($status->valid_from?->toDateString())->toBe('2023-09-01')
        ->and($status->expires_at?->toDateString())->toBe('2026-09-01');
});
