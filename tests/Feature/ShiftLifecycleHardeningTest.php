<?php

namespace Tests\Feature;

use App\Domain\Hr\Models\HrAttendanceSession;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\Timesheet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShiftLifecycleHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected User $staff;

    protected Site $site;

    protected Client $client;

    protected ServiceContext $serviceContext;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RbacSeeder::class);

        $this->staff = User::factory()->create([
            'role' => 'support_worker',
            'approved_at' => now(),
        ]);

        $supportRole = Role::query()->where('name', 'support_worker')->firstOrFail();
        $this->staff->roles()->syncWithoutDetaching([$supportRole->id]);

        $shiftUpdatePermissionId = Permission::query()
            ->where('key', 'shifts.update')
            ->value('id');

        if ($shiftUpdatePermissionId) {
            $supportRole->permissions()->syncWithoutDetaching([$shiftUpdatePermissionId]);
        }

        $this->site = Site::factory()->create(['name' => 'Kowhai House']);
        $this->serviceContext = ServiceContext::factory()->create([
            'name' => 'Residential Support',
            'type' => 'residential',
            'is_active' => true,
        ]);
        $this->client = Client::factory()->create([
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
        ]);

        HrEmployeeProfile::query()->create([
            'tenant_id' => 1,
            'user_id' => $this->staff->id,
            'employee_number' => 'EMP-SHIFT-'.$this->staff->id,
            'work_email' => $this->staff->email,
            'position_title' => 'Support Worker',
            'position_role' => 'support_worker',
            'employment_type' => 'full_time',
            'start_date' => now()->subMonth()->toDateString(),
            'is_active' => true,
            'primary_site_id' => $this->site->id,
            'secondary_site_ids' => [],
        ]);
    }

    public function test_shift_completion_is_blocked_while_attendance_session_is_still_open(): void
    {
        $shift = Shift::factory()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => $this->staff->id,
            'starts_at' => now()->subHours(4),
            'ends_at' => now()->subMinutes(30),
            'actual_starts_at' => now()->subHours(4),
            'actual_ends_at' => null,
            'status' => 'in_progress',
            'created_by' => $this->staff->id,
        ]);

        HrAttendanceSession::query()->create([
            'tenant_id' => $this->staff->tenant_id,
            'user_id' => $this->staff->id,
            'shift_id' => $shift->id,
            'site_id' => $this->site->id,
            'clock_in_at' => now()->subHours(4),
            'clock_out_at' => null,
            'break_minutes' => 0,
            'status' => 'open',
            'source' => 'manual',
            'created_by' => $this->staff->id,
        ]);

        $this->actingAs($this->staff)
            ->patch("/shifts/{$shift->id}/complete", [
                'final_note_body' => 'Still working through the open attendance session.',
                'create_timesheet' => false,
            ])
            ->assertSessionHasErrors(['status']);

        $this->assertDatabaseHas('shifts', [
            'id' => $shift->id,
            'status' => 'in_progress',
        ]);
    }

    public function test_shift_completion_uses_closed_attendance_evidence_to_complete_and_sync_draft_timesheet(): void
    {
        $clockIn = now()->subHours(7)->startOfMinute();
        $clockOut = now()->subMinutes(20)->startOfMinute();

        $shift = Shift::factory()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => $this->staff->id,
            'starts_at' => $clockIn->copy(),
            'ends_at' => $clockOut->copy(),
            'actual_starts_at' => null,
            'actual_ends_at' => null,
            'status' => 'in_progress',
            'expected_break_minutes' => 15,
            'location' => 'Unit 4',
            'created_by' => $this->staff->id,
        ]);

        $session = HrAttendanceSession::query()->create([
            'tenant_id' => $this->staff->tenant_id,
            'user_id' => $this->staff->id,
            'shift_id' => $shift->id,
            'site_id' => $this->site->id,
            'clock_in_at' => $clockIn,
            'clock_out_at' => $clockOut,
            'break_minutes' => 15,
            'status' => 'closed',
            'source' => 'manual',
            'created_by' => $this->staff->id,
            'closed_by' => $this->staff->id,
        ]);

        $this->actingAs($this->staff)
            ->patch("/shifts/{$shift->id}/complete", [
                'final_note_body' => 'Shift completed with attendance-confirmed timings.',
                'create_timesheet' => true,
            ])
            ->assertSessionHas('success', 'Shift completed.');

        $shift->refresh();

        $this->assertSame('completed', $shift->status);
        $this->assertTrue($shift->actual_starts_at->equalTo($clockIn));
        $this->assertTrue($shift->actual_ends_at->equalTo($clockOut));

        $timesheet = Timesheet::query()
            ->where('shift_id', $shift->id)
            ->where('user_id', $this->staff->id)
            ->firstOrFail();

        $this->assertSame('draft', $timesheet->status);
        $this->assertSame($session->id, $timesheet->attendance_session_id);
        $this->assertTrue($timesheet->starts_at->equalTo($clockIn));
        $this->assertTrue($timesheet->ends_at->equalTo($clockOut));
        $this->assertSame($this->site->id, $timesheet->shift_site_id);
        $this->assertSame($this->serviceContext->id, $timesheet->shift_service_context_id);
        $this->assertSame($this->site->name, $timesheet->shift_site_name_snapshot);
        $this->assertSame($this->staff->name, $timesheet->staff_name_snapshot);
        $this->assertSame('clear', $timesheet->reconciliation_status);
        $this->assertSame('none', $timesheet->reconciliation_severity);
    }
}
