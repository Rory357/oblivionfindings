<?php

use App\Domain\Hr\Models\HrComplianceMatrix;
use App\Domain\Hr\Models\HrComplianceRequirement;
use App\Domain\Hr\Models\HrDriverEligibility;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrStaffComplianceStatus;
use App\Models\Role;
use App\Models\User;
use App\Notifications\ComplianceReminderNotification;
use Illuminate\Support\Facades\Notification;

function makeProfile(User $user, string $title = 'Support Worker'): void
{
    HrEmployeeProfile::query()->create([
        'tenant_id' => 1,
        'user_id' => $user->id,
        'employee_number' => 'EMP-' . $user->id,
        'work_email' => $user->email,
        'position_title' => $title,
        'position_role' => 'support_worker',
        'employment_type' => 'full_time',
        'start_date' => now()->subYear()->toDateString(),
        'is_active' => true,
    ]);
}

beforeEach(function () {
    $this->seed(\Database\Seeders\RbacSeeder::class);

    $this->hr = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    makeProfile($this->hr, 'HR Manager');
    $hrRole = Role::query()->where('name', 'hr')->first();
    if ($hrRole) {
        $this->hr->roles()->syncWithoutDetaching([$hrRole->id]);
    }

    $this->staff = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    makeProfile($this->staff);

    $this->requirement = HrComplianceRequirement::query()->create([
        'tenant_id' => 1,
        'code' => 'FA-01',
        'name' => 'First Aid Certificate',
        'category' => 'Health & Safety',
        'check_type' => 'manual',
        'hard_stop' => true,
        'is_active' => true,
        'validity_months' => 24,
        'renewal_reminder_days' => 60,
        'created_by' => $this->hr->id,
    ]);
});

test('overview renders hero + rollup props', function () {
    $response = $this->actingAs($this->hr)->get('/hr/compliance');
    $response->assertOk();

    $hero = $response->inertiaProps('hero');
    expect($hero['summary']['total_staff'])->toBeGreaterThanOrEqual(2);
    expect($hero['chips'])->toBeArray();
    expect($response->inertiaProps('wizard')['requirements'])->toBeArray();
});

test('record compliance status writes a manual row with notes', function () {
    $response = $this->actingAs($this->hr)->post("/hr/compliance/staff/{$this->staff->id}/status", [
        'requirement_id' => $this->requirement->id,
        'status' => 'compliant',
        'valid_from' => now()->toDateString(),
        'expires_at' => now()->addYear()->toDateString(),
        'notes' => 'Verified St John certificate.',
    ]);

    $response->assertSessionHas('success');

    $this->assertDatabaseHas('hr_staff_compliance_status', [
        'tenant_id' => 1,
        'user_id' => $this->staff->id,
        'requirement_id' => $this->requirement->id,
        'status' => 'compliant',
        'evidence_type' => 'manual',
        'notes' => 'Verified St John certificate.',
    ]);
});

test('manual status survives a matrix re-evaluation', function () {
    // Assign the requirement to the staff role so evaluateStaff considers it.
    HrComplianceMatrix::query()->create([
        'tenant_id' => 1,
        'requirement_id' => $this->requirement->id,
        'role' => 'support_worker',
        'site_type' => null,
        'is_mandatory' => true,
    ]);
    $supportRole = Role::query()->where('name', 'support_worker')->first();
    if ($supportRole) {
        $this->staff->roles()->syncWithoutDetaching([$supportRole->id]);
    }

    HrStaffComplianceStatus::query()->create([
        'tenant_id' => 1,
        'user_id' => $this->staff->id,
        'requirement_id' => $this->requirement->id,
        'status' => 'compliant',
        'evidence_type' => 'manual',
        'valid_from' => now()->toDateString(),
        'expires_at' => now()->addYear()->toDateString(),
    ]);

    app(\App\Domain\Hr\Services\ComplianceMatrixService::class)->evaluateStaff($this->staff->fresh());

    // Still compliant (not reset to not_started by the source sweep).
    $this->assertDatabaseHas('hr_staff_compliance_status', [
        'user_id' => $this->staff->id,
        'requirement_id' => $this->requirement->id,
        'status' => 'compliant',
        'evidence_type' => 'manual',
    ]);
});

test('waive lifts a hard-stop with an exemption reason', function () {
    $status = HrStaffComplianceStatus::query()->create([
        'tenant_id' => 1,
        'user_id' => $this->staff->id,
        'requirement_id' => $this->requirement->id,
        'status' => 'expired',
        'evidence_type' => 'manual',
        'expires_at' => now()->subMonth()->toDateString(),
    ]);

    $response = $this->actingAs($this->hr)->post("/hr/compliance/status/{$status->id}/exempt", [
        'exemption_reason' => 'Awaiting renewal course next week.',
        'acknowledge' => true,
    ]);

    $response->assertSessionHas('success');
    $status->refresh();
    expect($status->status)->toBe('compliant');
    expect($status->exemption_reason)->toBe('Awaiting renewal course next week.');
    expect($status->exempted_by)->toBe($this->hr->id);
});

test('bulk record writes statuses for many staff', function () {
    $other = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    makeProfile($other);

    $response = $this->actingAs($this->hr)->post('/hr/compliance/bulk-record', [
        'user_ids' => [$this->staff->id, $other->id],
        'requirement_id' => $this->requirement->id,
        'status' => 'compliant',
    ]);

    $response->assertSessionHas('success');
    expect(HrStaffComplianceStatus::query()->where('requirement_id', $this->requirement->id)->count())->toBe(2);
});

test('bulk remind notifies the selected staff', function () {
    Notification::fake();

    $this->actingAs($this->hr)->post('/hr/compliance/bulk-remind', [
        'user_ids' => [$this->staff->id],
    ])->assertSessionHas('success');

    Notification::assertSentTo($this->staff, ComplianceReminderNotification::class);
});

test('assign creates matrix rows for roles', function () {
    $response = $this->actingAs($this->hr)->post('/hr/compliance/assign', [
        'requirement_ids' => [$this->requirement->id],
        'roles' => ['support_worker', 'team_lead'],
        'is_mandatory' => true,
    ]);

    $response->assertSessionHas('success');
    $this->assertDatabaseHas('hr_compliance_matrix', [
        'requirement_id' => $this->requirement->id,
        'role' => 'support_worker',
        'is_mandatory' => true,
    ]);
    $this->assertDatabaseHas('hr_compliance_matrix', [
        'requirement_id' => $this->requirement->id,
        'role' => 'team_lead',
    ]);
});

test('staff export streams a csv', function () {
    HrStaffComplianceStatus::query()->create([
        'tenant_id' => 1,
        'user_id' => $this->staff->id,
        'requirement_id' => $this->requirement->id,
        'status' => 'compliant',
        'evidence_type' => 'manual',
    ]);

    $response = $this->actingAs($this->hr)->get('/hr/compliance/export?dataset=staff');
    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('text/csv');
});

test('an expired driver licence hard-stops shift assignment', function () {
    $req = HrComplianceRequirement::query()->create([
        'tenant_id' => 1,
        'code' => 'DL-01',
        'name' => 'Valid Driver Licence',
        'category' => 'Eligibility',
        'check_type' => 'driver_licence',
        'hard_stop' => true,
        'is_active' => true,
        'created_by' => $this->hr->id,
    ]);
    HrComplianceMatrix::query()->create([
        'tenant_id' => 1,
        'requirement_id' => $req->id,
        'role' => 'support_worker',
        'site_type' => null,
        'is_mandatory' => true,
    ]);
    $supportRole = Role::query()->where('name', 'support_worker')->first();
    $this->staff->roles()->syncWithoutDetaching([$supportRole->id]);

    HrDriverEligibility::query()->create([
        'tenant_id' => 1,
        'user_id' => $this->staff->id,
        'licence_number' => 'AB123456',
        'licence_class' => '2',
        'licence_expires_at' => now()->subMonth()->toDateString(),
        'status' => 'eligible',
    ]);

    $result = app(\App\Domain\Hr\Services\ComplianceMatrixService::class)->canAssignToShift($this->staff->fresh());

    expect($result['blocked'])->toBeTrue();
    expect(collect($result['failures'])->pluck('code'))->toContain('DL-01');
});

test('a current driver licence does not hard-stop shift assignment', function () {
    $req = HrComplianceRequirement::query()->create([
        'tenant_id' => 1,
        'code' => 'DL-01',
        'name' => 'Valid Driver Licence',
        'category' => 'Eligibility',
        'check_type' => 'driver_licence',
        'hard_stop' => true,
        'is_active' => true,
        'created_by' => $this->hr->id,
    ]);
    HrComplianceMatrix::query()->create([
        'tenant_id' => 1,
        'requirement_id' => $req->id,
        'role' => 'support_worker',
        'site_type' => null,
        'is_mandatory' => true,
    ]);
    $supportRole = Role::query()->where('name', 'support_worker')->first();
    $this->staff->roles()->syncWithoutDetaching([$supportRole->id]);

    HrDriverEligibility::query()->create([
        'tenant_id' => 1,
        'user_id' => $this->staff->id,
        'licence_number' => 'AB123456',
        'licence_class' => '2',
        'licence_expires_at' => now()->addYears(3)->toDateString(),
        'status' => 'eligible',
    ]);

    $result = app(\App\Domain\Hr\Services\ComplianceMatrixService::class)->canAssignToShift($this->staff->fresh());

    expect(collect($result['failures'])->pluck('code'))->not->toContain('DL-01');
});

test('driver show page renders for a record', function () {
    $driver = HrDriverEligibility::query()->create([
        'tenant_id' => 1,
        'user_id' => $this->staff->id,
        'licence_number' => 'AB123456',
        'licence_class' => '2',
        'licence_endorsements' => ['P', 'V'],
        'licence_expires_at' => now()->addYears(3)->toDateString(),
        'status' => 'eligible',
        'can_drive_clients' => true,
    ]);

    $response = $this->actingAs($this->hr)->get("/hr/compliance/drivers/{$driver->id}");
    $response->assertOk();
    expect($response->inertiaProps('driver')['name'])->toBe($this->staff->name);
});
