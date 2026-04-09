<?php

namespace Tests\Feature;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\Role;
use App\Models\Shift;
use App\Models\Site;
use App\Models\Timesheet;
use App\Models\TimesheetAmendment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayrollAdjustmentsQueueTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $staff;
    protected Client $client;
    protected Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RbacSeeder::class);

        $this->admin = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
        $this->admin->roles()->attach(Role::where('name', 'admin')->first());

        $this->staff = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $this->staff->roles()->attach(Role::where('name', 'support_worker')->first());

        $this->site = Site::factory()->create();
        $this->client = Client::factory()->create(['site_id' => $this->site->id]);

        foreach ([$this->admin, $this->staff] as $user) {
            HrEmployeeProfile::query()->create([
                'tenant_id' => 1,
                'user_id' => $user->id,
                'employee_number' => 'PAQ-' . $user->id,
                'work_email' => $user->email,
                'position_title' => 'Test',
                'position_role' => $user->role,
                'employment_type' => 'full_time',
                'start_date' => now()->subMonth()->toDateString(),
                'is_active' => true,
                'primary_site_id' => $this->site->id,
                'secondary_site_ids' => [],
            ]);
        }
    }

    // ── Approved payroll-linked amendment appears in queue ───────────────

    public function test_approved_payroll_linked_amendment_appears_in_queue(): void
    {
        $amendment = $this->createPayrollLinkedAmendment();

        $response = $this->actingAs($this->admin)
            ->get('/operations/timesheets/payroll-adjustments');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('operations/timesheets/payroll-adjustments')
            ->has('amendments.data', 1)
            ->where('amendments.data.0.id', $amendment->id)
            ->where('amendments.data.0.staff_name', $this->staff->name)
        );
    }

    // ── Non-payroll-linked amendment does not appear ─────────────────────

    public function test_non_payroll_amendment_does_not_appear(): void
    {
        $this->createNonPayrollAmendment();

        $response = $this->actingAs($this->admin)
            ->get('/operations/timesheets/payroll-adjustments');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('amendments.data', 0)
        );
    }

    // ── Already-applied amendment does not appear ────────────────────────

    public function test_applied_amendment_does_not_appear(): void
    {
        $amendment = $this->createPayrollLinkedAmendment();
        $amendment->update(['applied_at' => now()]);

        $response = $this->actingAs($this->admin)
            ->get('/operations/timesheets/payroll-adjustments');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('amendments.data', 0)
        );
    }

    // ── Queue data includes key fields ──────────────────────────────────

    public function test_queue_data_includes_operational_fields(): void
    {
        $this->createPayrollLinkedAmendment();

        $response = $this->actingAs($this->admin)
            ->get('/operations/timesheets/payroll-adjustments');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('amendments.data.0', fn ($item) => $item
                ->has('id')
                ->has('timesheet_id')
                ->has('staff_name')
                ->has('work_date')
                ->has('original_values')
                ->has('proposed_values')
                ->has('reason')
                ->has('reviewed_at')
                ->has('reviewed_by')
                ->has('timesheet_url')
                ->etc()
            )
        );
    }

    // ── Mark as processed updates applied_at ────────────────────────────

    public function test_mark_processed_sets_applied_at(): void
    {
        $amendment = $this->createPayrollLinkedAmendment();

        $this->assertNull($amendment->applied_at);

        $response = $this->actingAs($this->admin)
            ->post("/operations/timesheets/amendments/{$amendment->id}/mark-processed");

        $response->assertSessionHas('success');

        $amendment->refresh();
        $this->assertNotNull($amendment->applied_at);
    }

    public function test_mark_processed_is_idempotent(): void
    {
        $amendment = $this->createPayrollLinkedAmendment();
        $amendment->update(['applied_at' => now()->subHour()]);

        $response = $this->actingAs($this->admin)
            ->post("/operations/timesheets/amendments/{$amendment->id}/mark-processed");

        $response->assertSessionHas('success', 'This adjustment has already been marked as processed.');
    }

    // ── No duplicate entries ────────────────────────────────────────────

    public function test_no_duplicate_entries_for_same_amendment(): void
    {
        $amendment = $this->createPayrollLinkedAmendment();

        $response = $this->actingAs($this->admin)
            ->get('/operations/timesheets/payroll-adjustments');

        $response->assertInertia(fn ($page) => $page
            ->has('amendments.data', 1)
        );

        // Query the page again — still exactly 1.
        $response2 = $this->actingAs($this->admin)
            ->get('/operations/timesheets/payroll-adjustments');

        $response2->assertInertia(fn ($page) => $page
            ->has('amendments.data', 1)
        );
    }

    // ── Rejected/pending amendments don't appear ────────────────────────

    public function test_pending_amendment_does_not_appear(): void
    {
        $timesheet = $this->createPayrollLinkedTimesheet();

        TimesheetAmendment::query()->create([
            'timesheet_id' => $timesheet->id,
            'status' => TimesheetAmendment::STATUS_PENDING,
            'original_values' => ['break_minutes' => 30],
            'proposed_values' => ['break_minutes' => 15],
            'reason' => 'Correction needed',
            'requested_by' => $this->staff->id,
            'requested_at' => now(),
            'payroll_adjustment_required' => true,
        ]);

        $response = $this->actingAs($this->admin)
            ->get('/operations/timesheets/payroll-adjustments');

        $response->assertInertia(fn ($page) => $page->has('amendments.data', 0));
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    protected function createPayrollLinkedTimesheet(): Timesheet
    {
        $shift = Shift::factory()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'user_id' => $this->staff->id,
            'status' => 'completed',
            'actual_starts_at' => now()->subHours(8),
            'actual_ends_at' => now()->subHours(4),
            'started_by' => $this->staff->id,
            'completed_by' => $this->staff->id,
            'created_by' => $this->staff->id,
        ]);

        $timesheet = Timesheet::factory()->create([
            'shift_id' => $shift->id,
            'user_id' => $this->staff->id,
            'client_id' => $this->client->id,
            'status' => 'draft',
            'created_by' => $this->admin->id,
            'staff_name_snapshot' => $this->staff->name,
            'client_name_snapshot' => $this->client->first_name,
            'shift_site_name_snapshot' => $this->site->name,
        ]);

        // Transition to approved + payroll-linked quietly.
        $timesheet->forceFill([
            'status' => 'approved',
            'approved_at' => now()->subDay(),
            'approved_by' => $this->admin->id,
            'exported_to_payroll_at' => now()->subDay(),
            'payroll_reference' => 'PR-2026-04',
        ])->saveQuietly();

        return $timesheet->fresh();
    }

    protected function createPayrollLinkedAmendment(): TimesheetAmendment
    {
        $timesheet = $this->createPayrollLinkedTimesheet();

        return TimesheetAmendment::query()->create([
            'timesheet_id' => $timesheet->id,
            'status' => TimesheetAmendment::STATUS_APPROVED,
            'original_values' => ['break_minutes' => 30],
            'proposed_values' => ['break_minutes' => 15],
            'reason' => 'Break was shorter than recorded',
            'requested_by' => $this->staff->id,
            'requested_at' => now()->subDays(2),
            'reviewed_by' => $this->admin->id,
            'reviewed_at' => now()->subDay(),
            'payroll_adjustment_required' => true,
            'applied_at' => null,
        ]);
    }

    protected function createNonPayrollAmendment(): TimesheetAmendment
    {
        $shift = Shift::factory()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'user_id' => $this->staff->id,
            'status' => 'completed',
            'actual_starts_at' => now()->subHours(8),
            'actual_ends_at' => now()->subHours(4),
            'started_by' => $this->staff->id,
            'completed_by' => $this->staff->id,
            'created_by' => $this->staff->id,
        ]);

        $timesheet = Timesheet::factory()->create([
            'shift_id' => $shift->id,
            'user_id' => $this->staff->id,
            'client_id' => $this->client->id,
            'status' => 'draft',
            'created_by' => $this->admin->id,
        ]);

        $timesheet->forceFill([
            'status' => 'approved',
            'approved_at' => now()->subDay(),
            'approved_by' => $this->admin->id,
        ])->saveQuietly();

        return TimesheetAmendment::query()->create([
            'timesheet_id' => $timesheet->id,
            'status' => TimesheetAmendment::STATUS_APPROVED,
            'original_values' => ['notes' => 'old'],
            'proposed_values' => ['notes' => 'new'],
            'reason' => 'Typo fix',
            'requested_by' => $this->staff->id,
            'requested_at' => now()->subDay(),
            'reviewed_by' => $this->admin->id,
            'reviewed_at' => now(),
            'payroll_adjustment_required' => false,
            'applied_at' => now(),
        ]);
    }
}
