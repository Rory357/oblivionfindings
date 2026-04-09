<?php

namespace Tests\Unit\Jobs;

use App\Jobs\ReconcileTimesheetsJob;
use App\Models\Client;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\Timesheet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReconcileTimesheetsJobTest extends TestCase
{
    use RefreshDatabase;

    protected Site $site;
    protected Client $client;
    protected ServiceContext $serviceContext;
    protected User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->site = Site::factory()->create();
        $this->serviceContext = ServiceContext::factory()->create();
        $this->client = Client::factory()->create(['site_id' => $this->site->id]);
        $this->staff = User::factory()->create(['approved_at' => now()]);
    }

    // ── Draft timesheet updated when shift changes ──────────────────────

    public function test_draft_timesheet_updated_when_shift_times_change(): void
    {
        $shift = $this->makeShift([
            'starts_at' => now()->subHours(8),
            'ends_at' => now()->subHours(4),
            'actual_starts_at' => now()->subHours(8),
            'actual_ends_at' => now()->subHours(4),
        ]);

        $timesheet = $this->makeTimesheet($shift, [
            'starts_at' => now()->subHours(8),
            'ends_at' => now()->subHours(4),
            'status' => 'draft',
        ]);

        // Shift times change (e.g. coordinator corrected end time).
        $newEnd = now()->subHours(3);
        $shift->update(['actual_ends_at' => $newEnd]);

        (new ReconcileTimesheetsJob)->handle(app(\App\Services\Operations\TimesheetReconciliationService::class));

        $timesheet->refresh();

        // Timesheet end time should now match the updated shift.
        $this->assertTrue(
            abs($timesheet->ends_at->diffInMinutes($newEnd)) < 1,
            'Draft timesheet end time should sync to updated shift actual_ends_at'
        );
    }

    public function test_draft_timesheet_updated_when_break_minutes_change(): void
    {
        $shift = $this->makeShift([
            'expected_break_minutes' => 30,
        ]);

        $timesheet = $this->makeTimesheet($shift, [
            'break_minutes' => 30,
            'status' => 'draft',
        ]);

        $shift->update(['expected_break_minutes' => 45]);

        (new ReconcileTimesheetsJob)->handle(app(\App\Services\Operations\TimesheetReconciliationService::class));

        $timesheet->refresh();
        $this->assertEquals(45, $timesheet->break_minutes);
    }

    public function test_draft_timesheet_updated_when_sleepover_flag_changes(): void
    {
        $shift = $this->makeShift(['is_sleepover' => false]);
        $timesheet = $this->makeTimesheet($shift, ['sleepover' => false, 'status' => 'draft']);

        $shift->update(['is_sleepover' => true]);

        (new ReconcileTimesheetsJob)->handle(app(\App\Services\Operations\TimesheetReconciliationService::class));

        $timesheet->refresh();
        $this->assertTrue($timesheet->sleepover);
    }

    // ── No update when no changes detected ──────────────────────────────

    public function test_no_update_when_shift_matches_timesheet(): void
    {
        $start = now()->subHours(8)->startOfMinute();
        $end = now()->subHours(4)->startOfMinute();

        $shift = $this->makeShift([
            'starts_at' => $start,
            'ends_at' => $end,
            'actual_starts_at' => $start,
            'actual_ends_at' => $end,
            'expected_break_minutes' => 30,
        ]);

        $timesheet = $this->makeTimesheet($shift, [
            'starts_at' => $start,
            'ends_at' => $end,
            'break_minutes' => 30,
            'status' => 'draft',
        ]);

        $originalUpdatedAt = $timesheet->updated_at;

        // Small delay to detect timestamp change.
        $this->travel(5)->seconds();

        (new ReconcileTimesheetsJob)->handle(app(\App\Services\Operations\TimesheetReconciliationService::class));

        $timesheet->refresh();

        // Reconciliation fields may update but operational fields should not change.
        $this->assertTrue(
            abs($timesheet->starts_at->diffInMinutes($start)) < 1,
            'Start time should remain unchanged'
        );
        $this->assertTrue(
            abs($timesheet->ends_at->diffInMinutes($end)) < 1,
            'End time should remain unchanged'
        );
        $this->assertEquals(30, $timesheet->break_minutes);
    }

    // ── Submitted timesheet behaviour ───────────────────────────────────

    public function test_submitted_timesheet_not_overwritten_but_reconciled(): void
    {
        $shift = $this->makeShift([
            'starts_at' => now()->subHours(8),
            'ends_at' => now()->subHours(4),
            'actual_starts_at' => now()->subHours(8),
            'actual_ends_at' => now()->subHours(4),
        ]);

        $originalStart = now()->subHours(9); // deliberately different from shift
        $timesheet = $this->makeTimesheet($shift, [
            'starts_at' => $originalStart,
            'ends_at' => now()->subHours(4),
            'status' => 'submitted',
            'submitted_at' => now()->subHour(),
        ]);

        (new ReconcileTimesheetsJob)->handle(app(\App\Services\Operations\TimesheetReconciliationService::class));

        $timesheet->refresh();

        // Data should NOT be overwritten for submitted timesheets.
        $this->assertTrue(
            abs($timesheet->starts_at->diffInMinutes($originalStart)) < 1,
            'Submitted timesheet start time should not be overwritten'
        );

        // But reconciliation status should be updated.
        $this->assertNotNull($timesheet->reconciliation_detected_at);
    }

    // ── Approved timesheet untouched ────────────────────────────────────

    public function test_approved_timesheet_completely_skipped(): void
    {
        $shift = $this->makeShift([
            'starts_at' => now()->subHours(8),
            'ends_at' => now()->subHours(4),
        ]);

        $timesheet = $this->makeTimesheet($shift, [
            'starts_at' => now()->subHours(9), // different from shift
            'ends_at' => now()->subHours(3),   // different from shift
            'status' => 'approved',
            'approved_at' => now()->subHour(),
            'approved_by' => User::factory()->create()->id,
        ]);

        $originalReconciledAt = $timesheet->reconciliation_detected_at;

        (new ReconcileTimesheetsJob)->handle(app(\App\Services\Operations\TimesheetReconciliationService::class));

        $timesheet->refresh();

        // Approved timesheets should not be processed at all.
        $this->assertEquals('approved', $timesheet->status);
        $this->assertEquals($originalReconciledAt, $timesheet->reconciliation_detected_at);
    }

    // ── Idempotency ─────────────────────────────────────────────────────

    public function test_running_twice_does_not_duplicate_work(): void
    {
        $shift = $this->makeShift([
            'starts_at' => now()->subHours(8),
            'ends_at' => now()->subHours(4),
            'actual_starts_at' => now()->subHours(8),
            'actual_ends_at' => now()->subHours(3), // different from planned
        ]);

        $timesheet = $this->makeTimesheet($shift, [
            'starts_at' => now()->subHours(8),
            'ends_at' => now()->subHours(4), // matches planned, not actual
            'status' => 'draft',
        ]);

        $service = app(\App\Services\Operations\TimesheetReconciliationService::class);

        // First run — should sync.
        (new ReconcileTimesheetsJob)->handle($service);
        $timesheet->refresh();
        $firstEnd = $timesheet->ends_at->copy();

        // Second run — should be idempotent (no further changes).
        $this->travel(10)->seconds();
        (new ReconcileTimesheetsJob)->handle($service);
        $timesheet->refresh();

        $this->assertTrue(
            abs($timesheet->ends_at->diffInMinutes($firstEnd)) < 1,
            'Second run should not change the already-synced timesheet'
        );

        // Only one timesheet should exist.
        $this->assertEquals(1, Timesheet::where('shift_id', $shift->id)->count());
    }

    // ── Edge cases ──────────────────────────────────────────────────────

    public function test_timesheet_without_shift_is_skipped(): void
    {
        $timesheet = Timesheet::factory()->create([
            'shift_id' => null,
            'status' => 'draft',
            'user_id' => $this->staff->id,
            'client_id' => $this->client->id,
            'created_by' => $this->staff->id,
        ]);

        // Should not crash.
        (new ReconcileTimesheetsJob)->handle(app(\App\Services\Operations\TimesheetReconciliationService::class));

        $timesheet->refresh();
        $this->assertEquals('draft', $timesheet->status);
    }

    public function test_old_timesheets_outside_window_are_skipped(): void
    {
        $shift = $this->makeShift([
            'starts_at' => now()->subDays(30),
            'ends_at' => now()->subDays(30)->addHours(4),
            'actual_starts_at' => now()->subDays(30),
            'actual_ends_at' => now()->subDays(30)->addHours(5), // different
            'created_at' => now()->subDays(30),
        ]);

        $timesheet = $this->makeTimesheet($shift, [
            'starts_at' => now()->subDays(30),
            'ends_at' => now()->subDays(30)->addHours(4),
            'status' => 'draft',
        ]);

        // Force created_at to be old (outside 14-day window).
        Timesheet::withoutEvents(fn () => $timesheet->forceFill(['created_at' => now()->subDays(30)])->saveQuietly());

        $originalEnd = $timesheet->ends_at->copy();

        (new ReconcileTimesheetsJob(windowDays: 14))->handle(app(\App\Services\Operations\TimesheetReconciliationService::class));

        $timesheet->refresh();

        // Should not have been updated because it's outside the window.
        $this->assertTrue(
            abs($timesheet->ends_at->diffInMinutes($originalEnd)) < 1,
            'Timesheet outside window should not be processed'
        );
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    protected function makeShift(array $overrides = []): Shift
    {
        $defaults = [
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => $this->staff->id,
            'status' => 'completed',
            'started_by' => $this->staff->id,
            'completed_by' => $this->staff->id,
            'created_by' => $this->staff->id,
        ];

        $merged = array_merge($defaults, $overrides);

        // Completed shifts require actual start/end evidence.
        if (($merged['status'] ?? '') === 'completed') {
            $merged['actual_starts_at'] ??= $merged['starts_at'] ?? now()->subHours(8);
            $merged['actual_ends_at'] ??= $merged['ends_at'] ?? now()->subHours(4);
        }

        return Shift::factory()->create($merged);
    }

    protected function makeTimesheet(Shift $shift, array $overrides = []): Timesheet
    {
        // Create as draft first to avoid model event firing reconciliation
        // workflow checks (which block submitted/approved timesheets for
        // completed shifts without attendance evidence after PR-F7).
        $targetStatus = $overrides['status'] ?? 'draft';
        $submittedAt = $overrides['submitted_at'] ?? null;
        $approvedAt = $overrides['approved_at'] ?? null;
        $approvedBy = $overrides['approved_by'] ?? null;
        unset($overrides['status'], $overrides['submitted_at'], $overrides['approved_at'], $overrides['approved_by']);

        $timesheet = Timesheet::factory()->create(array_merge([
            'shift_id' => $shift->id,
            'user_id' => $shift->user_id,
            'client_id' => $shift->client_id,
            'work_date' => ($shift->actual_starts_at ?? $shift->starts_at)?->toDateString(),
            'starts_at' => $shift->actual_starts_at ?? $shift->starts_at,
            'ends_at' => $shift->actual_ends_at ?? $shift->ends_at,
            'break_minutes' => $shift->expected_break_minutes ?? 0,
            'sleepover' => (bool) $shift->is_sleepover,
            'on_call' => (bool) $shift->is_on_call,
            'status' => 'draft',
            'created_by' => $this->staff->id,
        ], $overrides));

        if ($targetStatus !== 'draft') {
            $timesheet->forceFill(array_filter([
                'status' => $targetStatus,
                'submitted_at' => $submittedAt ?? now(),
                'approved_at' => $approvedAt,
                'approved_by' => $approvedBy,
            ]))->saveQuietly();
        }

        return $timesheet->fresh();
    }
}
