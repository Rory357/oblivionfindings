<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Site;
use App\Models\Timesheet;
use App\Models\TimesheetAmendment;
use App\Models\User;
use App\Services\Operations\TimesheetAmendmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class TimesheetAmendmentWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $manager;

    protected User $staff;

    protected Client $client;

    protected Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RbacSeeder::class);

        $this->admin = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
        $this->admin->roles()->attach(Role::where('name', 'admin')->first());

        $this->manager = User::factory()->create(['role' => 'coordinator', 'approved_at' => now()]);
        $this->manager->roles()->attach(Role::where('name', 'coordinator')->first());

        $this->staff = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $this->staff->roles()->attach(Role::where('name', 'support_worker')->first());

        $this->site = Site::factory()->create();
        $this->client = Client::factory()->create([
            'site_id' => $this->site->id,
            'service_context_id' => ServiceContext::factory()->create()->id,
        ]);
    }

    // ──────────────────────────────────────────────
    // Approved timesheets remain immutable
    // ──────────────────────────────────────────────

    public function test_approved_timesheet_cannot_be_edited_directly(): void
    {
        $timesheet = $this->makeApprovedTimesheet();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Approved or payroll-linked timesheets are immutable');

        $timesheet->update(['break_minutes' => 20]);
    }

    // ──────────────────────────────────────────────
    // Amendment request
    // ──────────────────────────────────────────────

    public function test_amendment_can_be_requested_for_approved_timesheet(): void
    {
        $timesheet = $this->makeApprovedTimesheet();
        $service = app(TimesheetAmendmentService::class);

        $amendment = $service->request($timesheet, $this->admin, [
            'break_minutes' => 30,
            'notes' => 'Staff confirmed 30 min break, not 15.',
        ], 'Break duration was incorrectly recorded.');

        $this->assertSame(TimesheetAmendment::STATUS_PENDING, $amendment->status);
        $this->assertSame($timesheet->id, $amendment->timesheet_id);
        $this->assertSame($this->admin->id, $amendment->requested_by);
        $this->assertSame(15, $amendment->original_values['break_minutes']);
        $this->assertSame(30, $amendment->proposed_values['break_minutes']);
        $this->assertSame('Break duration was incorrectly recorded.', $amendment->reason);
    }

    public function test_amendment_captures_original_values_correctly(): void
    {
        $timesheet = $this->makeApprovedTimesheet();
        $service = app(TimesheetAmendmentService::class);

        $amendment = $service->request($timesheet, $this->admin, [
            'starts_at' => '2026-04-05 08:45:00',
            'break_minutes' => 0,
        ], 'Correcting start time.');

        $this->assertSame($timesheet->starts_at->toISOString(), $amendment->original_values['starts_at']);
        $this->assertSame(15, $amendment->original_values['break_minutes']);
    }

    public function test_amendment_cannot_be_requested_for_non_approved_timesheet(): void
    {
        $timesheet = Timesheet::factory()->create([
            'user_id' => $this->staff->id,
            'client_id' => $this->client->id,
            'shift_id' => null,
            'shift_site_id' => $this->site->id,
            'status' => 'draft',
        ]);

        $service = app(TimesheetAmendmentService::class);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Only approved timesheets');
        $service->request($timesheet, $this->admin, ['break_minutes' => 30], 'test');
    }

    public function test_duplicate_pending_amendment_is_rejected(): void
    {
        $timesheet = $this->makeApprovedTimesheet();
        $service = app(TimesheetAmendmentService::class);

        $service->request($timesheet, $this->admin, ['break_minutes' => 30], 'First request.');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('already has a pending amendment');
        $service->request($timesheet, $this->admin, ['break_minutes' => 45], 'Second request.');
    }

    public function test_non_amendable_fields_are_rejected(): void
    {
        $timesheet = $this->makeApprovedTimesheet();
        $service = app(TimesheetAmendmentService::class);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('cannot be amended');
        $service->request($timesheet, $this->admin, [
            'user_id' => 999,
        ], 'Trying to change staff.');
    }

    // ──────────────────────────────────────────────
    // Amendment approval
    // ──────────────────────────────────────────────

    public function test_approved_amendment_applies_corrected_values(): void
    {
        $timesheet = $this->makeApprovedTimesheet();
        $service = app(TimesheetAmendmentService::class);

        $amendment = $service->request($timesheet, $this->admin, [
            'break_minutes' => 30,
        ], 'Correcting break.');

        $service->approve($amendment, $this->manager, 'Verified with staff.');

        $amendment->refresh();
        $this->assertSame(TimesheetAmendment::STATUS_APPROVED, $amendment->status);
        $this->assertSame($this->manager->id, $amendment->reviewed_by);
        $this->assertNotNull($amendment->applied_at);

        $timesheet->refresh();
        $this->assertSame(30, (int) $timesheet->break_minutes);
        $this->assertSame('approved', $timesheet->status); // Still approved
    }

    public function test_self_approval_of_amendment_is_blocked(): void
    {
        $timesheet = $this->makeApprovedTimesheet();
        $service = app(TimesheetAmendmentService::class);

        $amendment = $service->request($timesheet, $this->admin, [
            'break_minutes' => 30,
        ], 'Correcting break.');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('cannot approve your own');
        $service->approve($amendment, $this->admin);
    }

    // ──────────────────────────────────────────────
    // Amendment rejection
    // ──────────────────────────────────────────────

    public function test_rejected_amendment_does_not_alter_original(): void
    {
        $timesheet = $this->makeApprovedTimesheet();
        $originalBreak = (int) $timesheet->break_minutes;
        $service = app(TimesheetAmendmentService::class);

        $amendment = $service->request($timesheet, $this->admin, [
            'break_minutes' => 30,
        ], 'Correcting break.');

        $service->reject($amendment, $this->manager, 'Not justified.');

        $amendment->refresh();
        $this->assertSame(TimesheetAmendment::STATUS_REJECTED, $amendment->status);
        $this->assertSame('Not justified.', $amendment->review_notes);

        $timesheet->refresh();
        $this->assertSame($originalBreak, (int) $timesheet->break_minutes);
    }

    // ──────────────────────────────────────────────
    // Payroll safety
    // ──────────────────────────────────────────────

    public function test_payroll_linked_timesheet_amendment_is_flagged(): void
    {
        $timesheet = $this->makeApprovedTimesheet();
        $timesheet->forceFill([
            'exported_to_payroll_at' => now()->subDay(),
            'payroll_reference' => 'PR-001',
        ])->saveQuietly();

        $service = app(TimesheetAmendmentService::class);

        $amendment = $service->request($timesheet, $this->admin, [
            'break_minutes' => 30,
        ], 'Post-export correction.');

        $this->assertTrue($amendment->payroll_adjustment_required);
    }

    public function test_non_payroll_linked_amendment_not_flagged(): void
    {
        $timesheet = $this->makeApprovedTimesheet();
        $service = app(TimesheetAmendmentService::class);

        $amendment = $service->request($timesheet, $this->admin, [
            'break_minutes' => 30,
        ], 'Pre-export correction.');

        $this->assertFalse($amendment->payroll_adjustment_required);
    }

    public function test_exported_timesheet_amendment_approval_does_not_mutate_original(): void
    {
        $timesheet = $this->makeApprovedTimesheet();
        $originalBreak = (int) $timesheet->break_minutes;

        $timesheet->forceFill([
            'exported_to_payroll_at' => now()->subDay(),
            'payroll_reference' => 'PR-001',
        ])->saveQuietly();

        $service = app(TimesheetAmendmentService::class);

        $amendment = $service->request($timesheet, $this->admin, [
            'break_minutes' => 30,
        ], 'Post-export correction.');

        $service->approve($amendment, $this->manager, 'Confirmed with staff.');

        // Amendment is approved
        $amendment->refresh();
        $this->assertSame(TimesheetAmendment::STATUS_APPROVED, $amendment->status);
        $this->assertSame($this->manager->id, $amendment->reviewed_by);
        $this->assertNotNull($amendment->reviewed_at);
        $this->assertSame('Confirmed with staff.', $amendment->review_notes);

        // But original timesheet values are unchanged
        $timesheet->refresh();
        $this->assertSame($originalBreak, (int) $timesheet->break_minutes);

        // applied_at is null — values not written to timesheet
        $this->assertNull($amendment->applied_at);

        // Payroll adjustment flag remains true
        $this->assertTrue($amendment->payroll_adjustment_required);
    }

    public function test_exported_timesheet_amendment_preserves_payroll_reference(): void
    {
        $timesheet = $this->makeApprovedTimesheet();

        $timesheet->forceFill([
            'exported_to_payroll_at' => now()->subDay(),
            'payroll_reference' => 'PR-001',
        ])->saveQuietly();

        $service = app(TimesheetAmendmentService::class);

        $amendment = $service->request($timesheet, $this->admin, [
            'break_minutes' => 30,
        ], 'Post-export fix.');

        $service->approve($amendment, $this->manager);

        $timesheet->refresh();
        $this->assertSame('PR-001', $timesheet->payroll_reference);
        $this->assertNotNull($timesheet->exported_to_payroll_at);
    }

    public function test_non_exported_timesheet_amendment_still_applies_values(): void
    {
        $timesheet = $this->makeApprovedTimesheet();
        $service = app(TimesheetAmendmentService::class);

        $amendment = $service->request($timesheet, $this->admin, [
            'break_minutes' => 30,
        ], 'Pre-export correction.');

        $service->approve($amendment, $this->manager);

        $amendment->refresh();
        $this->assertSame(TimesheetAmendment::STATUS_APPROVED, $amendment->status);
        $this->assertNotNull($amendment->applied_at);
        $this->assertFalse($amendment->payroll_adjustment_required);

        $timesheet->refresh();
        $this->assertSame(30, (int) $timesheet->break_minutes);
    }

    // ──────────────────────────────────────────────
    // Idempotency and status guards
    // ──────────────────────────────────────────────

    public function test_already_approved_amendment_cannot_be_approved_again(): void
    {
        $timesheet = $this->makeApprovedTimesheet();
        $service = app(TimesheetAmendmentService::class);

        $amendment = $service->request($timesheet, $this->admin, ['break_minutes' => 30], 'test');
        $service->approve($amendment, $this->manager);

        $this->expectException(ValidationException::class);
        $service->approve($amendment->fresh(), $this->manager);
    }

    public function test_rejected_amendment_cannot_be_approved(): void
    {
        $timesheet = $this->makeApprovedTimesheet();
        $service = app(TimesheetAmendmentService::class);

        $amendment = $service->request($timesheet, $this->admin, ['break_minutes' => 30], 'test');
        $service->reject($amendment, $this->manager, 'No.');

        $this->expectException(ValidationException::class);
        $service->approve($amendment->fresh(), $this->manager);
    }

    public function test_new_amendment_allowed_after_previous_rejected(): void
    {
        $timesheet = $this->makeApprovedTimesheet();
        $service = app(TimesheetAmendmentService::class);

        $first = $service->request($timesheet, $this->admin, ['break_minutes' => 30], 'First attempt.');
        $service->reject($first, $this->manager, 'No.');

        // Should succeed — no pending amendment blocking
        $second = $service->request($timesheet, $this->admin, ['break_minutes' => 25], 'Second attempt with evidence.');
        $this->assertSame(TimesheetAmendment::STATUS_PENDING, $second->status);
    }

    // ──────────────────────────────────────────────
    // Audit trail
    // ──────────────────────────────────────────────

    public function test_amendment_lifecycle_creates_audit_trail(): void
    {
        $timesheet = $this->makeApprovedTimesheet();
        $service = app(TimesheetAmendmentService::class);

        $amendment = $service->request($timesheet, $this->admin, ['break_minutes' => 30], 'Correction.');
        $service->approve($amendment, $this->manager, 'Confirmed.');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'timesheet.amendment.requested',
            'auditable_type' => Timesheet::class,
            'auditable_id' => $timesheet->id,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'timesheet.amendment.approved',
            'auditable_type' => Timesheet::class,
            'auditable_id' => $timesheet->id,
        ]);
    }

    protected function makeApprovedTimesheet(): Timesheet
    {
        return Timesheet::factory()->approved()->create([
            'user_id' => $this->staff->id,
            'client_id' => $this->client->id,
            'shift_site_id' => $this->site->id,
            'shift_id' => null,
            'approved_by' => $this->manager->id,
            'break_minutes' => 15,
            'client_name_snapshot' => 'Test Client',
            'staff_name_snapshot' => $this->staff->name,
            'shift_type_snapshot' => 'standard',
        ]);
    }
}
