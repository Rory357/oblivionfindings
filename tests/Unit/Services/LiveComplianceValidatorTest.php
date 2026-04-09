<?php

namespace Tests\Unit\Services;

use App\Domain\Hr\Models\HrComplianceMatrix;
use App\Domain\Hr\Models\HrComplianceRequirement;
use App\Domain\Hr\Models\HrStaffComplianceStatus;
use App\Domain\Hr\Services\ComplianceMatrixService;
use App\Domain\Hr\Services\LiveComplianceValidator;
use App\Models\Role;
use App\Models\StaffCredential;
use App\Models\StaffTrainingRecord;
use App\Models\StaffBackgroundCheck;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LiveComplianceValidatorTest extends TestCase
{
    use RefreshDatabase;

    protected LiveComplianceValidator $validator;

    protected ComplianceMatrixService $service;

    protected User $staff;

    protected Role $role;

    protected function setUp(): void
    {
        parent::setUp();

        $this->validator = new LiveComplianceValidator();
        $this->service = app(ComplianceMatrixService::class);

        $this->role = Role::firstOrCreate(['name' => 'support_worker']);
        $this->staff = User::factory()->create(['approved_at' => now()]);
        $this->staff->roles()->attach($this->role);
        $this->staff->load('roles');
    }

    // ── Core: cached compliant + live expired → blocked ─────────────────

    public function test_cached_compliant_but_live_expired_credential_blocks(): void
    {
        $requirement = $this->createHardStopRequirement('first_aid', 'First Aid Certificate', 'credential');

        // Cached status says compliant (from last night's run).
        HrStaffComplianceStatus::query()->create([
            'tenant_id' => $this->staff->tenant_id ?? 1,
            'user_id' => $this->staff->id,
            'requirement_id' => $requirement->id,
            'status' => 'compliant',
            'evidence_type' => 'credential',
            'valid_from' => now()->subYear(),
            'expires_at' => now()->addMonth(), // cache thinks it's still valid
            'last_checked_at' => now()->subHours(12),
            'next_check_at' => now()->addHours(12),
        ]);

        // But the live credential actually expired today.
        StaffCredential::query()->create([
            'user_id' => $this->staff->id,
            'type' => 'first_aid',
            'issuer' => 'Red Cross NZ',
            'issued_at' => now()->subYears(2),
            'expires_at' => now()->subDay(), // expired yesterday
        ]);

        // Live validator should catch the expiry.
        $result = $this->validator->validateHardStops($this->staff);
        $this->assertFalse($result['passed']);
        $this->assertCount(1, $result['failures']);
        $this->assertEquals('first_aid', $result['failures'][0]['code']);
        $this->assertStringContainsString('expired', $result['failures'][0]['reason']);

        // The combined canAssignToShift should block.
        $assignResult = $this->service->canAssignToShift($this->staff);
        $this->assertTrue($assignResult['blocked']);
        $this->assertNotEmpty($assignResult['failures']);
    }

    // ── Core: cached compliant + live valid → allowed ───────────────────

    public function test_cached_compliant_and_live_valid_allows(): void
    {
        $requirement = $this->createHardStopRequirement('first_aid', 'First Aid Certificate', 'credential');

        HrStaffComplianceStatus::query()->create([
            'tenant_id' => $this->staff->tenant_id ?? 1,
            'user_id' => $this->staff->id,
            'requirement_id' => $requirement->id,
            'status' => 'compliant',
            'evidence_type' => 'credential',
            'valid_from' => now()->subMonth(),
            'expires_at' => now()->addYear(),
            'last_checked_at' => now()->subHours(6),
            'next_check_at' => now()->addHours(18),
        ]);

        StaffCredential::query()->create([
            'user_id' => $this->staff->id,
            'type' => 'first_aid',
            'issuer' => 'Red Cross NZ',
            'issued_at' => now()->subMonth(),
            'expires_at' => now()->addYear(), // still valid
        ]);

        $result = $this->validator->validateHardStops($this->staff);
        $this->assertTrue($result['passed']);
        $this->assertEmpty($result['failures']);

        $assignResult = $this->service->canAssignToShift($this->staff);
        $this->assertTrue($assignResult['allowed']);
        $this->assertFalse($assignResult['blocked']);
    }

    // ── Core: missing hard-stop record → blocked ────────────────────────

    public function test_missing_credential_for_hard_stop_blocks(): void
    {
        $this->createHardStopRequirement('first_aid', 'First Aid Certificate', 'credential');

        // No StaffCredential exists for this user.

        $result = $this->validator->validateHardStops($this->staff);
        $this->assertFalse($result['passed']);
        $this->assertCount(1, $result['failures']);
        $this->assertStringContainsString('missing', $result['failures'][0]['reason']);
    }

    // ── Message specificity ─────────────────────────────────────────────

    public function test_failure_message_includes_requirement_name_and_expiry(): void
    {
        $this->createHardStopRequirement('first_aid', 'First Aid Certificate', 'credential');

        $expiryDate = now()->subDays(3);
        StaffCredential::query()->create([
            'user_id' => $this->staff->id,
            'type' => 'first_aid',
            'issuer' => 'Red Cross NZ',
            'issued_at' => now()->subYears(2),
            'expires_at' => $expiryDate,
        ]);

        $result = $this->validator->validateHardStops($this->staff);
        $this->assertFalse($result['passed']);

        $reason = $result['failures'][0]['reason'];
        $this->assertStringContainsString('First Aid Certificate', $reason);
        $this->assertStringContainsString($expiryDate->format('j M Y'), $reason);
        $this->assertEquals($expiryDate->toDateString(), $result['failures'][0]['expires_at']);
    }

    // ── Non-hard-stop requirements are not live-validated ────────────────

    public function test_non_hard_stop_requirements_are_not_live_validated(): void
    {
        // Create a NON-hard-stop requirement with expired credential.
        $requirement = HrComplianceRequirement::query()->create([
            'tenant_id' => $this->staff->tenant_id ?? 1,
            'code' => 'optional_cert',
            'name' => 'Optional Certificate',
            'category' => 'training',
            'check_type' => 'credential',
            'hard_stop' => false, // not a hard stop
            'is_active' => true,
        ]);

        HrComplianceMatrix::query()->create([
            'tenant_id' => $this->staff->tenant_id ?? 1,
            'requirement_id' => $requirement->id,
            'role' => 'support_worker',
            'is_mandatory' => false,
        ]);

        StaffCredential::query()->create([
            'user_id' => $this->staff->id,
            'type' => 'optional_cert',
            'issuer' => 'Some Org',
            'issued_at' => now()->subYears(2),
            'expires_at' => now()->subDay(), // expired
        ]);

        // Live validator should NOT check non-hard-stop requirements.
        $result = $this->validator->validateHardStops($this->staff);
        $this->assertTrue($result['passed']);
        $this->assertEmpty($result['failures']);
    }

    // ── Training check type ─────────────────────────────────────────────

    public function test_expired_training_hard_stop_blocks(): void
    {
        $courseId = \Illuminate\Support\Facades\DB::table('training_courses')->insertGetId([
            'name' => 'Medication Administration',
            'category' => 'clinical',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $requirement = $this->createHardStopRequirement('med_admin', 'Medication Administration', 'training_course');
        $requirement->update(['reference_id' => $courseId, 'validity_months' => 12]);

        StaffTrainingRecord::query()->create([
            'user_id' => $this->staff->id,
            'training_course_id' => $courseId,
            'status' => 'completed',
            'completed_at' => now()->subMonths(14), // 14 months ago, validity is 12
        ]);

        $result = $this->validator->validateHardStops($this->staff);
        $this->assertFalse($result['passed']);
        $this->assertStringContainsString('Medication Administration', $result['failures'][0]['reason']);
        $this->assertStringContainsString('expired', $result['failures'][0]['reason']);
    }

    public function test_valid_training_passes(): void
    {
        $courseId = \Illuminate\Support\Facades\DB::table('training_courses')->insertGetId([
            'name' => 'Medication Administration',
            'category' => 'clinical',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $requirement = $this->createHardStopRequirement('med_admin', 'Medication Administration', 'training_course');
        $requirement->update(['reference_id' => $courseId, 'validity_months' => 12]);

        StaffTrainingRecord::query()->create([
            'user_id' => $this->staff->id,
            'training_course_id' => $courseId,
            'status' => 'completed',
            'completed_at' => now()->subMonths(6), // 6 months ago, validity is 12
        ]);

        $result = $this->validator->validateHardStops($this->staff);
        $this->assertTrue($result['passed']);
    }

    // ── Background check ────────────────────────────────────────────────

    public function test_missing_background_check_blocks(): void
    {
        $this->createHardStopRequirement('police_vetting', 'Police Vetting', 'background_check');

        // No background check exists.
        $result = $this->validator->validateHardStops($this->staff);
        $this->assertFalse($result['passed']);
        $this->assertStringContainsString('missing', $result['failures'][0]['reason']);
    }

    public function test_valid_background_check_passes(): void
    {
        $this->createHardStopRequirement('police_vetting', 'Police Vetting', 'background_check');

        StaffBackgroundCheck::query()->create([
            'user_id' => $this->staff->id,
            'check_type' => 'police_check',
            'status' => 'clear',
            'check_date' => now()->subMonths(6),
            'created_by' => $this->staff->id,
        ]);

        $result = $this->validator->validateHardStops($this->staff);
        $this->assertTrue($result['passed']);
    }

    // ── Multiple failures ───────────────────────────────────────────────

    public function test_multiple_hard_stop_failures_all_surfaced(): void
    {
        $this->createHardStopRequirement('first_aid', 'First Aid Certificate', 'credential');
        $this->createHardStopRequirement('police_vetting', 'Police Vetting', 'background_check');

        // Both missing — no credentials or checks exist.

        $result = $this->validator->validateHardStops($this->staff);
        $this->assertFalse($result['passed']);
        $this->assertCount(2, $result['failures']);

        $codes = array_column($result['failures'], 'code');
        $this->assertContains('first_aid', $codes);
        $this->assertContains('police_vetting', $codes);
    }

    // ── Manual requirements fall back to cached status ───────────────────

    public function test_manual_requirement_uses_cached_status(): void
    {
        $requirement = $this->createHardStopRequirement('manual_check', 'Manual Verification', 'manual');

        // Manual requirement with no compliant status → blocked.
        $result = $this->validator->validateHardStops($this->staff);
        $this->assertFalse($result['passed']);
        $this->assertStringContainsString('not been manually verified', $result['failures'][0]['reason']);

        // Add compliant manual status → passes.
        HrStaffComplianceStatus::query()->create([
            'tenant_id' => $this->staff->tenant_id ?? 1,
            'user_id' => $this->staff->id,
            'requirement_id' => $requirement->id,
            'status' => 'compliant',
            'evidence_type' => 'manual',
            'last_checked_at' => now(),
            'next_check_at' => now()->addDay(),
        ]);

        $result = $this->validator->validateHardStops($this->staff->fresh());
        $this->assertTrue($result['passed']);
    }

    // ── Existing cached workflow still works ─────────────────────────────

    public function test_cached_expired_hard_stop_still_blocks_without_live_data(): void
    {
        $requirement = $this->createHardStopRequirement('med_training', 'Medication Training', 'manual');

        HrStaffComplianceStatus::query()->create([
            'tenant_id' => $this->staff->tenant_id ?? 1,
            'user_id' => $this->staff->id,
            'requirement_id' => $requirement->id,
            'status' => 'expired',
            'evidence_type' => 'manual',
            'last_checked_at' => now()->subDay(),
            'next_check_at' => now(),
        ]);

        $assignResult = $this->service->canAssignToShift($this->staff);
        $this->assertTrue($assignResult['blocked']);
        $this->assertNotEmpty($assignResult['failures']);
    }

    // ── No hard-stop requirements → passes ──────────────────────────────

    public function test_no_hard_stop_requirements_passes(): void
    {
        // No requirements at all.
        $result = $this->validator->validateHardStops($this->staff);
        $this->assertTrue($result['passed']);
        $this->assertEmpty($result['failures']);
    }

    // ── Eligibility message specificity ──────────────────────────────────

    public function test_eligibility_compliance_message_uses_specific_reason(): void
    {
        $this->createHardStopRequirement('first_aid', 'First Aid Certificate', 'credential');

        $expiryDate = now()->subDays(5);
        StaffCredential::query()->create([
            'user_id' => $this->staff->id,
            'type' => 'first_aid',
            'issuer' => 'Red Cross NZ',
            'issued_at' => now()->subYears(2),
            'expires_at' => $expiryDate,
        ]);

        $assignResult = $this->service->canAssignToShift($this->staff);
        $this->assertTrue($assignResult['blocked']);

        // The failure should have a specific reason, not just generic status.
        $failure = collect($assignResult['failures'])->firstWhere('code', 'first_aid');
        $this->assertNotNull($failure);
        $this->assertStringContainsString('First Aid Certificate', $failure['reason']);
        $this->assertStringContainsString('expired', $failure['reason']);
    }

    // ── Helper ──────────────────────────────────────────────────────────

    protected function createHardStopRequirement(string $code, string $name, string $checkType): HrComplianceRequirement
    {
        $requirement = HrComplianceRequirement::query()->create([
            'tenant_id' => $this->staff->tenant_id ?? 1,
            'code' => $code,
            'name' => $name,
            'category' => 'compliance',
            'check_type' => $checkType,
            'hard_stop' => true,
            'is_active' => true,
        ]);

        HrComplianceMatrix::query()->create([
            'tenant_id' => $this->staff->tenant_id ?? 1,
            'requirement_id' => $requirement->id,
            'role' => 'support_worker',
            'is_mandatory' => true,
        ]);

        return $requirement;
    }
}
