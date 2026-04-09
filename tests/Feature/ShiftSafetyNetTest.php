<?php

namespace Tests\Feature;

use App\Domain\Hr\Models\HrAttendanceSession;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\ShiftHandover;
use App\Models\Site;
use App\Models\Timesheet;
use App\Models\User;
use App\Services\ShiftCancellationService;
use App\Services\ShiftHandoverService;
use App\Services\ShiftTimelineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ShiftSafetyNetTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $staff;

    protected User $incomingStaff;

    protected Site $site;

    protected Client $client;

    protected ServiceContext $serviceContext;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RbacSeeder::class);

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'approved_at' => now(),
        ]);
        $this->admin->roles()->attach(Role::where('name', 'admin')->first());

        $this->staff = User::factory()->create([
            'role' => 'support_worker',
            'approved_at' => now(),
        ]);
        $supportRole = Role::query()->where('name', 'support_worker')->firstOrFail();
        $this->staff->roles()->attach($supportRole);

        $this->incomingStaff = User::factory()->create([
            'role' => 'support_worker',
            'approved_at' => now(),
        ]);
        $this->incomingStaff->roles()->attach($supportRole);

        $shiftUpdatePermissionId = Permission::query()->where('key', 'shifts.update')->value('id');
        if ($shiftUpdatePermissionId) {
            $supportRole->permissions()->syncWithoutDetaching([$shiftUpdatePermissionId]);
        }

        $this->site = Site::factory()->create(['name' => 'Koru House']);
        $this->serviceContext = ServiceContext::factory()->create([
            'name' => 'Residential Support',
            'type' => 'residential',
            'is_active' => true,
        ]);
        $this->client = Client::factory()->create([
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
        ]);

        foreach ([$this->staff, $this->incomingStaff] as $staffUser) {
            HrEmployeeProfile::query()->create([
                'tenant_id' => 1,
                'user_id' => $staffUser->id,
                'employee_number' => 'EMP-SAFE-'.$staffUser->id,
                'work_email' => $staffUser->email,
                'position_title' => 'Support Worker',
                'position_role' => 'support_worker',
                'employment_type' => 'full_time',
                'start_date' => now()->subMonth()->toDateString(),
                'is_active' => true,
                'primary_site_id' => $this->site->id,
                'secondary_site_ids' => [],
            ]);
        }
    }

    public function test_shift_completion_is_idempotent_on_repeat_submission(): void
    {
        $clockIn = now()->subHours(6)->startOfMinute();
        $clockOut = now()->subMinutes(10)->startOfMinute();

        $shift = Shift::factory()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => $this->staff->id,
            'starts_at' => $clockIn,
            'ends_at' => $clockOut,
            'actual_starts_at' => $clockIn,
            'status' => 'in_progress',
            'created_by' => $this->staff->id,
        ]);

        HrAttendanceSession::query()->create([
            'tenant_id' => $this->staff->tenant_id,
            'user_id' => $this->staff->id,
            'shift_id' => $shift->id,
            'site_id' => $this->site->id,
            'clock_in_at' => $clockIn,
            'clock_out_at' => $clockOut,
            'break_minutes' => 0,
            'status' => 'closed',
            'source' => 'manual',
            'created_by' => $this->staff->id,
            'closed_by' => $this->staff->id,
        ]);

        $payload = [
            'final_note_body' => 'Shift wrapped up safely.',
        ];

        $this->actingAs($this->staff)
            ->patch("/shifts/{$shift->id}/complete", $payload)
            ->assertSessionHas('success', 'Shift completed. Draft timesheet created.');

        $this->actingAs($this->staff)
            ->patch("/shifts/{$shift->id}/complete", $payload)
            ->assertSessionHas('success', 'Shift already completed.');

        $this->assertDatabaseCount('client_notes', 1);
        $this->assertSame(
            1,
            \App\Models\TimelineEvent::query()
                ->where('type', ShiftTimelineService::COMPLETED_EVENT_TYPE)
                ->where('source_type', Shift::class)
                ->where('source_id', $shift->id)
                ->count()
        );
    }

    public function test_timesheet_approval_is_idempotent_on_repeat_submission(): void
    {
        $timesheet = Timesheet::factory()->approved()->create([
            'user_id' => $this->staff->id,
            'client_id' => $this->client->id,
            'shift_id' => null,
            'approved_by' => $this->admin->id,
            'client_name_snapshot' => trim($this->client->first_name.' '.$this->client->last_name),
            'staff_name_snapshot' => $this->staff->name,
            'shift_type_snapshot' => 'standard',
        ]);

        $firstApprovedAt = $timesheet->approved_at;

        $this->actingAs($this->admin)
            ->post("/timesheets/{$timesheet->id}/approve")
            ->assertSessionHas('success', 'Timesheet already approved.');

        $timesheet->refresh();

        $this->assertSame('approved', $timesheet->status);
        $this->assertTrue($timesheet->approved_at->equalTo($firstApprovedAt));
    }

    public function test_handover_submission_is_idempotent_on_repeat_submission(): void
    {
        $outgoingShift = Shift::factory()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => $this->staff->id,
            'starts_at' => now()->subHours(8),
            'ends_at' => now()->subHour(),
            'status' => 'in_progress',
            'created_by' => $this->staff->id,
        ]);

        $incomingShift = Shift::factory()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => $this->incomingStaff->id,
            'starts_at' => now()->addMinutes(30),
            'ends_at' => now()->addHours(8),
            'status' => 'scheduled',
            'created_by' => $this->admin->id,
        ]);

        $handover = ShiftHandover::factory()->draft()->create([
            'outgoing_shift_id' => $outgoingShift->id,
            'incoming_shift_id' => $incomingShift->id,
            'client_id' => $this->client->id,
            'outgoing_staff_id' => $this->staff->id,
            'incoming_staff_id' => $this->incomingStaff->id,
        ]);

        $this->actingAs($this->staff)
            ->patch("/operations/handovers/{$handover->id}/submit")
            ->assertSessionHas('success', 'Handover submitted.');

        $submittedAt = $handover->fresh()->submitted_at;

        $this->actingAs($this->staff)
            ->patch("/operations/handovers/{$handover->id}/submit")
            ->assertSessionHas('success', 'Handover submitted.');

        $handover->refresh();

        $this->assertSame(ShiftHandoverService::STATUS_SUBMITTED, $handover->status);
        $this->assertTrue(optional($handover->submitted_at)->equalTo($submittedAt));
        $this->assertSame(
            1,
            \App\Models\AuditLog::query()
                ->where('action', 'shift.handover.submitted')
                ->where('auditable_type', ShiftHandover::class)
                ->where('auditable_id', $handover->id)
                ->count()
        );
    }

    // ──────────────────────────────────────────────
    // Shift cancellation concurrency safety
    // ──────────────────────────────────────────────

    public function test_shift_cancellation_is_idempotent_on_repeat_call(): void
    {
        $shift = Shift::factory()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => $this->staff->id,
            'starts_at' => now()->addHours(2),
            'ends_at' => now()->addHours(6),
            'status' => 'scheduled',
            'created_by' => $this->admin->id,
        ]);

        $service = app(ShiftCancellationService::class);

        $firstResult = $service->cancel($shift, $this->admin);
        $this->assertFalse($firstResult['already_cancelled']);
        $this->assertSame('cancelled', $firstResult['shift']->status);

        $secondResult = $service->cancel($shift, $this->admin);
        $this->assertTrue($secondResult['already_cancelled']);
        $this->assertSame('cancelled', $secondResult['shift']->status);

        $this->assertDatabaseHas('shifts', [
            'id' => $shift->id,
            'status' => 'cancelled',
        ]);
    }

    public function test_cancelling_already_completed_shift_throws_validation_error(): void
    {
        $shift = Shift::factory()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => $this->staff->id,
            'starts_at' => now()->subHours(8),
            'ends_at' => now()->subHour(),
            'actual_starts_at' => now()->subHours(8),
            'actual_ends_at' => now()->subHour(),
            'status' => 'completed',
            'completed_by' => $this->staff->id,
            'created_by' => $this->admin->id,
        ]);

        $service = app(ShiftCancellationService::class);

        $this->expectException(ValidationException::class);
        $service->cancel($shift, $this->admin);
    }

    public function test_cancel_after_concurrent_completion_is_rejected(): void
    {
        // Simulate race: caller has a stale in-memory shift (in_progress),
        // but another request completed it in the database before we lock.
        $shift = Shift::factory()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => $this->staff->id,
            'starts_at' => now()->subHours(8),
            'ends_at' => now()->subHour(),
            'actual_starts_at' => now()->subHours(8),
            'status' => 'in_progress',
            'created_by' => $this->admin->id,
        ]);

        // Stale in-memory model thinks it's still in_progress
        $staleShift = $shift->replicate();
        $staleShift->id = $shift->id;
        $staleShift->exists = true;

        // Simulate the other request completing the shift in the database
        Shift::withoutEvents(function () use ($shift) {
            $shift->update([
                'status' => 'completed',
                'actual_ends_at' => now()->subHour(),
                'completed_by' => $this->staff->id,
            ]);
        });

        // The cancel service should re-fetch with lockForUpdate and see 'completed'
        $service = app(ShiftCancellationService::class);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Completed shifts are locked');
        $service->cancel($staleShift, $this->admin);

        // Shift remains completed
        $this->assertDatabaseHas('shifts', [
            'id' => $shift->id,
            'status' => 'completed',
        ]);
    }

    public function test_cancel_after_concurrent_cancellation_returns_idempotent(): void
    {
        // Simulate race: caller has a stale in-memory shift (scheduled),
        // but another request cancelled it in the database before we lock.
        $shift = Shift::factory()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => $this->staff->id,
            'starts_at' => now()->addHours(2),
            'ends_at' => now()->addHours(6),
            'status' => 'scheduled',
            'created_by' => $this->admin->id,
        ]);

        $staleShift = $shift->replicate();
        $staleShift->id = $shift->id;
        $staleShift->exists = true;

        // Simulate the other request cancelling first
        Shift::withoutEvents(function () use ($shift) {
            $shift->update(['status' => 'cancelled', 'actual_starts_at' => null, 'actual_ends_at' => null]);
        });

        $service = app(ShiftCancellationService::class);
        $result = $service->cancel($staleShift, $this->admin);

        $this->assertTrue($result['already_cancelled']);
        $this->assertSame('cancelled', $result['shift']->status);
    }
}
