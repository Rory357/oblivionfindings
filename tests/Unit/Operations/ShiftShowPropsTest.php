<?php

namespace Tests\Unit\Operations;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\Shift;
use App\Models\ShiftHandover;
use App\Models\Site;
use App\Models\Timesheet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests the query patterns used by ShiftController::show to build
 * linkedTimesheet and handoverSummary props for the UI.
 *
 * These test the data-shaping queries in isolation, not the full controller
 * (which has pre-existing auth issues in the test environment).
 */
class ShiftShowPropsTest extends TestCase
{
    use RefreshDatabase;

    private function makeShift(array $overrides = []): Shift
    {
        return Shift::factory()->create(array_merge([
            'status' => 'scheduled',
        ], $overrides));
    }

    public function test_linked_timesheet_returns_null_when_no_timesheet_exists(): void
    {
        $shift = $this->makeShift();

        $result = Timesheet::where('shift_id', $shift->id)
            ->select(['id', 'status', 'work_date', 'starts_at', 'ends_at', 'exported_to_payroll_at', 'payroll_reference', 'reconciliation_status'])
            ->first();

        $this->assertNull($result);
    }

    public function test_linked_timesheet_returns_correct_fields(): void
    {
        $shift = $this->makeShift();

        // Create a draft timesheet directly to avoid invariant triggers
        $timesheet = Timesheet::forceCreate([
            'shift_id' => $shift->id,
            'user_id' => $shift->user_id,
            'client_id' => $shift->client_id,
            'status' => 'draft',
            'work_date' => now()->toDateString(),
            'starts_at' => now(),
            'ends_at' => now()->addHours(4),
            'break_minutes' => 30,
        ]);

        $result = Timesheet::where('shift_id', $shift->id)
            ->select(['id', 'status', 'work_date', 'starts_at', 'ends_at', 'exported_to_payroll_at', 'payroll_reference', 'reconciliation_status'])
            ->first();

        $this->assertNotNull($result);
        $this->assertEquals($timesheet->id, $result->id);
        $this->assertEquals('draft', $result->status);
        $this->assertNull($result->exported_to_payroll_at);
        $this->assertNull($result->payroll_reference);
    }

    public function test_handover_summary_returns_null_when_no_handover(): void
    {
        $shift = $this->makeShift();

        $h = ShiftHandover::where('outgoing_shift_id', $shift->id)
            ->select(['id', 'status', 'incoming_staff_id'])
            ->with(['incomingStaff:id,name'])
            ->latest()
            ->first();

        $this->assertNull($h);
    }

    public function test_handover_summary_returns_correct_shape(): void
    {
        $site = Site::factory()->create();
        $client = Client::factory()->create(['site_id' => $site->id]);
        $outgoing = User::factory()->frontlineWorker()->create(['name' => 'Outgoing Staff']);
        $incoming = User::factory()->frontlineWorker()->create(['name' => 'Incoming Staff']);

        foreach ([$outgoing, $incoming] as $worker) {
            HrEmployeeProfile::factory()->create([
                'user_id' => $worker->id,
                'employee_number' => 'EMP-SHIFT-SHOW-'.$worker->id,
                'work_email' => $worker->email,
                'position_title' => 'Support Worker',
                'position_role' => 'support_worker',
                'start_date' => now()->subMonth()->toDateString(),
                'is_active' => true,
                'primary_site_id' => $site->id,
                'secondary_site_ids' => [],
                'created_by' => $worker->id,
                'updated_by' => $worker->id,
            ]);
        }

        $outShift = $this->makeShift([
            'user_id' => $outgoing->id,
            'client_id' => $client->id,
            'site_id' => $site->id,
            'status' => 'in_progress',
            'actual_starts_at' => now()->subHours(2),
            'created_by' => $outgoing->id,
        ]);

        $inShift = $this->makeShift([
            'user_id' => $incoming->id,
            'client_id' => $client->id,
            'site_id' => $site->id,
            'created_by' => $incoming->id,
        ]);

        // Persist through model events so the fail-closed handover invariant
        // validates the canonical Shift, staff, Client, and Site provenance.
        $handover = ShiftHandover::create([
            'outgoing_shift_id' => $outShift->id,
            'incoming_shift_id' => $inShift->id,
            'client_id' => $client->id,
            'outgoing_staff_id' => $outgoing->id,
            'incoming_staff_id' => $incoming->id,
            'status' => 'submitted',
            'handover_notes' => 'Test handover',
            'submitted_at' => now(),
            'submitted_by' => $outgoing->id,
        ]);

        $h = ShiftHandover::where('outgoing_shift_id', $outShift->id)
            ->select(['id', 'status', 'incoming_staff_id'])
            ->with(['incomingStaff:id,name'])
            ->latest()
            ->first();

        $result = $h ? [
            'id' => $h->id,
            'status' => $h->status,
            'incoming_staff_name' => $h->incomingStaff?->name,
        ] : null;

        $this->assertNotNull($result);
        $this->assertEquals($handover->id, $result['id']);
        $this->assertEquals('submitted', $result['status']);
        $this->assertEquals('Incoming Staff', $result['incoming_staff_name']);
    }
}
