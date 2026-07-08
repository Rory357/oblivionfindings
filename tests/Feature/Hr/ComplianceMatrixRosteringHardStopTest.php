<?php

use App\Domain\Hr\Models\HrComplianceRequirement;
use App\Domain\Hr\Models\HrStaffComplianceStatus;
use App\Domain\Rostering\RosterPublishValidator;
use App\Models\Client;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Services\ShiftStaffEligibilityService;

/**
 * Seam S6 — Compliance matrix → Rostering. A staff member with an EXPIRED
 * hard-stop compliance requirement must be a hard-stop when rostered:
 * ComplianceMatrixService::getHardStopFailures (the cached path, which reads the
 * user's hr_staff_compliance_status directly, role-independent) flags the
 * expired status → ShiftStaffEligibilityService::checkCompliance turns it into a
 * severity-`block` → RosterPublishValidator makes the roster un-publishable.
 * LiveComplianceValidator is role-scoped (needs the user's roles via the matrix)
 * and is skipped for a role-less test user, so the cached status is the sole
 * block — a clean proof that the compliance rule is enforced at rostering, not
 * merely computed. Complements the driver-licence proof in S3.
 */
function hrSeamComplianceShift(int $userId): Shift
{
    $site = Site::factory()->create();
    $client = Client::factory()->create(['site_id' => $site->id]);

    return Shift::factory()->create([
        'client_id' => $client->id,
        'site_id' => $site->id,
        'service_context_id' => ServiceContext::factory()->create()->id,
        'user_id' => $userId,
        'starts_at' => now()->addDay()->setTime(9, 0),
        'ends_at' => now()->addDay()->setTime(17, 0),
        'status' => 'scheduled',
        'created_by' => User::factory(),
    ]);
}

function hrSeamHardStopRequirement(int $creatorId, string $code, string $name): HrComplianceRequirement
{
    return HrComplianceRequirement::query()->create([
        'tenant_id' => 1,
        'code' => $code,
        'name' => $name,
        'category' => 'Eligibility',
        'check_type' => 'credential',
        'hard_stop' => true,
        'is_active' => true,
        'created_by' => $creatorId,
    ]);
}

test('S6 seam: an expired hard-stop compliance requirement blocks eligibility and hard-stops the roster', function () {
    $staff = User::factory()->create();
    $req = hrSeamHardStopRequirement($staff->id, 'FA-01', 'First Aid Certificate');

    HrStaffComplianceStatus::query()->create([
        'tenant_id' => 1,
        'user_id' => $staff->id,
        'requirement_id' => $req->id,
        'status' => 'expired',
        'expires_at' => now()->subMonth()->toDateString(),
    ]);

    $shift = hrSeamComplianceShift($staff->id);

    // The compliance rule computes a block naming the requirement.
    $result = app(ShiftStaffEligibilityService::class)->evaluate($shift, $staff->fresh())->toArray();
    expect(collect($result['blocked_reasons'] ?? [])->implode(' '))->toContain('First Aid Certificate');

    // Rostering ENFORCES it — the roster cannot be published (the hard-stop).
    $publish = app(RosterPublishValidator::class)->validateProposedShifts(collect([$shift]));
    expect($publish['can_publish'])->toBeFalse();
    expect(collect($publish['blocks'])->pluck('message')->implode(' '))->toContain('First Aid Certificate');
});

test('S6 seam: a compliant staff member is not blocked by the same requirement', function () {
    $staff = User::factory()->create();
    $req = hrSeamHardStopRequirement($staff->id, 'FA-02', 'First Aid Certificate');

    HrStaffComplianceStatus::query()->create([
        'tenant_id' => 1,
        'user_id' => $staff->id,
        'requirement_id' => $req->id,
        'status' => 'compliant',
        'expires_at' => now()->addYear()->toDateString(),
    ]);

    $shift = hrSeamComplianceShift($staff->id);

    $result = app(ShiftStaffEligibilityService::class)->evaluate($shift, $staff->fresh())->toArray();
    expect(collect($result['blocked_reasons'] ?? [])->implode(' '))->not->toContain('First Aid Certificate');
});
